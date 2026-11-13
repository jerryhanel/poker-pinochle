<?php
declare(strict_types=1);

namespace PokerPinochle\Engine;

use InvalidArgumentException;

final class Score
{
    public static function target(string $mode, int $players): int
    {
        if ($mode === 'teams') {
            $map = [4 => 50, 6 => 150, 8 => 200];
            if (!isset($map[$players])) {
                throw new InvalidArgumentException("No teams target for {$players} players.");
            }
            return $map[$players];
        }
        // cutthroat
        return 25 * $players;
    }

    /**
     * @param int          $biddingSideNo
     * @param int          $bid
     * @param array<int,int> $meldBySide       side_no => meld points (pre-forfeit)
     * @param array<int,int> $countersBySide   side_no => counter points captured in tricks
     * @param array<int,int> $tricksWonBySide  side_no => tricks won
     * @return array{
     *   sides: array<int,array{meld:int,counters:int,meldKept:bool,handScore:int}>,
     *   biddingSideNo:int, bid:int, made:bool, biddingTotal:int
     * }
     */
    public static function scoreHand(
        int $biddingSideNo,
        int $bid,
        array $meldBySide,
        array $countersBySide,
        array $tricksWonBySide
    ): array {
        $sideNos = array_keys($meldBySide + $countersBySide + $tricksWonBySide);
        sort($sideNos);

        $bMeldRaw  = (int) ($meldBySide[$biddingSideNo] ?? 0);
        $bCounters = (int) ($countersBySide[$biddingSideNo] ?? 0);
        $bTricks   = (int) ($tricksWonBySide[$biddingSideNo] ?? 0);
        $bMeldKept = $bTricks >= 1;

        $biddingTotal = ($bMeldKept ? $bMeldRaw : 0) + $bCounters;
        $made         = $biddingTotal >= $bid;

        $sides = [];
        foreach ($sideNos as $s) {
            $meld     = (int) ($meldBySide[$s] ?? 0);
            $counters = (int) ($countersBySide[$s] ?? 0);
            $tricks   = (int) ($tricksWonBySide[$s] ?? 0);
            $kept     = $tricks >= 1;

            if ($s === $biddingSideNo) {
                $handScore = $made ? $biddingTotal : -$bid;
            } else {
                // non-bidders keep what they captured (+ meld, if they took a trick)
                $handScore = ($kept ? $meld : 0) + $counters;
            }

            $sides[$s] = [
                'meld'      => $meld,
                'counters'  => $counters,
                'meldKept'  => $kept,
                'handScore' => $handScore,
            ];
        }

        return [
            'sides'         => $sides,
            'biddingSideNo' => $biddingSideNo,
            'bid'           => $bid,
            'made'          => $made,
            'biddingTotal'  => $biddingTotal,
        ];
    }

    /**
     * Add this hand's per-side scores to the running totals.
     * @param array<int,int> $running     side_no => total before the hand
     * @param array<int,int> $handScores  side_no => this hand's handScore
     * @return array<int,int> new running totals
     */
    public static function applyToTotals(array $running, array $handScores): array
    {
        foreach ($handScores as $s => $delta) {
            $running[$s] = (int) ($running[$s] ?? 0) + (int) $delta;
        }
        return $running;
    }

    /**
     * @param array<int,int> $running  side_no => total
     * @return int|null  winning side_no, or null if the game continues
     */
    public static function gameWinner(array $running, int $target): ?int
    {
        $winner = null;
        $best = null;
        foreach ($running as $s => $total) {
            if ($total >= $target && ($best === null || $total > $best)) {
                $best = $total;
                $winner = $s;
            }
        }
        return $winner;
    }
}
