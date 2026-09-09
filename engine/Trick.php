<?php
declare(strict_types=1);

require_once __DIR__ . '/Cards.php';

// -----------------------------------------------------------------------------
// Trick — strict "all-beat" legal-move rules, trick winner, counter count.
//
// A "trick so far" is an ordered list of plays:
//   [ ['seat'=>int, 'card'=>'AS'], ['seat'=>int, 'card'=>'9S'], ... ]
// The first entry is the lead. $trump is 'S'|'H'|'D'|'C'|'NT'.
//
// Legal-move ladder (checked in order):
//   1. Follow suit if you can — AND beat the highest card of the led suit
//      already in the trick, if you can.
//   2. Void in the led suit -> you must TRUMP, and if trump is already down
//      you must OVER-trump, if you can. (No trumping under a no-trump contract.)
//   3. Can't follow, can't trump -> sluff anything.
// Two identical cards can tie; the one played EARLIER wins the tie.
// -----------------------------------------------------------------------------

final class Trick
{
    /**
     * The subset of $hand the player is allowed to play right now.
     * If $played is empty the player is leading and every card is legal.
     */
    public static function legalPlays(array $hand, array $played, string $trump): array
    {
        $hand = array_values($hand);
        if (count($played) === 0) {
            return $hand;
        }

        $ledSuit   = Cards::suit($played[0]['card']);
        $trumpMode = ($trump !== 'NT');

        $inLed = Cards::ofSuit($hand, $ledSuit);
        if (count($inLed) > 0) {
            // Must follow. Must beat the highest LED-suit card in the trick if able.
            $highLedVal = -1;
            foreach ($played as $p) {
                if (Cards::suit($p['card']) === $ledSuit) {
                    $highLedVal = max($highLedVal, Cards::value($p['card']));
                }
            }
            $beaters = [];
            foreach ($inLed as $c) {
                if (Cards::value($c) > $highLedVal) {
                    $beaters[] = $c;
                }
            }
            return count($beaters) > 0 ? $beaters : $inLed;
        }

        // Void in led suit.
        if (!$trumpMode) {
            return $hand;   // no-trump: sluff anything
        }

        $trumps = Cards::ofSuit($hand, $trump);
        if (count($trumps) === 0) {
            return $hand;   // no led suit, no trump: sluff anything
        }

        // Have trump: must trump, and over-trump if trump already played.
        $highTrumpVal = -1;
        foreach ($played as $p) {
            if (Cards::suit($p['card']) === $trump) {
                $highTrumpVal = max($highTrumpVal, Cards::value($p['card']));
            }
        }
        if ($highTrumpVal < 0) {
            return $trumps;   // first to trump: any trump
        }
        $overs = [];
        foreach ($trumps as $c) {
            if (Cards::value($c) > $highTrumpVal) {
                $overs[] = $c;
            }
        }
        return count($overs) > 0 ? $overs : $trumps;
    }

    public static function isLegal(array $hand, array $played, string $trump, string $card): bool
    {
        return in_array($card, self::legalPlays($hand, $played, $trump), true);
    }

    /**
     * Index into $played of the winning play. $played must be a complete trick
     * (one entry per seat), but any non-empty prefix also resolves sensibly.
     */
    public static function winnerIndex(array $played, string $trump): int
    {
        if (count($played) === 0) {
            throw new InvalidArgumentException('Empty trick has no winner.');
        }
        $played    = array_values($played);
        $ledSuit   = Cards::suit($played[0]['card']);
        $trumpMode = ($trump !== 'NT');

        $bestIdx = 0;
        $bestVal = -1;
        $bestIsTrump = false;

        foreach ($played as $i => $p) {
            $suit = Cards::suit($p['card']);
            $val  = Cards::value($p['card']);
            $isTrump = ($trumpMode && $suit === $trump);

            if (!$isTrump && $suit !== $ledSuit) {
                continue; // off-suit sluff can never win
            }

            if ($isTrump && !$bestIsTrump) {
                $bestIdx = $i; $bestVal = $val; $bestIsTrump = true;
                continue;
            }
            if ($isTrump === $bestIsTrump && $val > $bestVal) {
                $bestIdx = $i; $bestVal = $val;
            }
            // equal value -> earlier play keeps it (no update)
        }

        return $bestIdx;
    }

    public static function winnerSeat(array $played, string $trump): int
    {
        return $played[self::winnerIndex($played, $trump)]['seat'];
    }

    /** Counter points (A/T/K = 1 each) contained in the trick. */
    public static function counters(array $played): int
    {
        $n = 0;
        foreach ($played as $p) {
            $n += Cards::counterPoints($p['card']);
        }
        return $n;
    }
}
