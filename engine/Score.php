<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// Score — one hand's outcome, plus running totals and game end.
//
//  * Only the BIDDING side can score on a hand. Its total = meld + counters
//    captured in tricks.
//      - total >= bid  -> "made": score the full total (it may exceed the bid).
//      - total <  bid   -> "set": forfeit ALL meld and score -(bid).
//  * A side must win at least ONE trick to keep its meld. (For non-bidding
//    sides this is moot — they score 0 regardless.)
//  * Every non-bidding side scores 0 for the hand.
//  * "Made" is >= : meld + counters exactly equal to the bid counts as made.
//
// Game targets:  teams   4p->50, 6p->150, 8p->200
//                cutthroat  25 x player_count  (3p->75, 5p->125, 7p->175)
// First side to reach the target ends the game (no "bid it out" rule).
// -----------------------------------------------------------------------------

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
                $handScore = 0;   // non-bidders never score
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
