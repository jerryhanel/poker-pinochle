<?php
declare(strict_types=1);

namespace PokerPinochle\Engine;

use InvalidArgumentException;

final class Trick
{
    /**
     * The subset of $hand the player is allowed to play right now.
     * If $played is empty the player is leading and every card is legal.
     */
    public static function legalPlays(array $hand, array $played, string $inSuit): array
    {
        $hand = array_values($hand);
        if (count($played) === 0) {
            return $hand;
        }

        $ledSuit    = Cards::suit($played[0]['card']);
        $inSuitMode = ($inSuit !== 'NS');

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
        if (!$inSuitMode) {
            return $hand;   // no suit in play: sluff anything
        }

        $inSuitCards = Cards::ofSuit($hand, $inSuit);
        if (count($inSuitCards) === 0) {
            return $hand;   // no led suit, none in suit either: sluff anything
        }

        // Have a card in suit: must play it, and beat it if already played.
        $highInSuitVal = -1;
        foreach ($played as $p) {
            if (Cards::suit($p['card']) === $inSuit) {
                $highInSuitVal = max($highInSuitVal, Cards::value($p['card']));
            }
        }
        if ($highInSuitVal < 0) {
            return $inSuitCards;   // first to play in suit: any card in suit
        }
        $overs = [];
        foreach ($inSuitCards as $c) {
            if (Cards::value($c) > $highInSuitVal) {
                $overs[] = $c;
            }
        }
        return count($overs) > 0 ? $overs : $inSuitCards;
    }

    public static function isLegal(array $hand, array $played, string $inSuit, string $card): bool
    {
        return in_array($card, self::legalPlays($hand, $played, $inSuit), true);
    }

    /**
     * Which rung of the all-beat ladder produced legalPlays() right now —
     * for explaining "why" a suggested card is legal. One of:
     *   'lead'         — leading the trick, no constraint.
     *   'follow_beat'  — must follow suit AND beat the highest of it so far.
     *   'follow_any'   — must follow suit; none of your cards can beat it.
     *   'insuit_beat'  — void in the led suit; must play in suit AND beat it.
     *   'insuit_any'   — void in the led suit; must play in suit; can't beat it.
     *   'free'         — nothing to follow or play in suit — sluff anything.
     */
    public static function legalPlayReason(array $hand, array $played, string $inSuit): string
    {
        $hand = array_values($hand);
        if (count($played) === 0) {
            return 'lead';
        }

        $ledSuit    = Cards::suit($played[0]['card']);
        $inSuitMode = ($inSuit !== 'NS');

        $inLed = Cards::ofSuit($hand, $ledSuit);
        if (count($inLed) > 0) {
            $highLedVal = -1;
            foreach ($played as $p) {
                if (Cards::suit($p['card']) === $ledSuit) {
                    $highLedVal = max($highLedVal, Cards::value($p['card']));
                }
            }
            foreach ($inLed as $c) {
                if (Cards::value($c) > $highLedVal) {
                    return 'follow_beat';
                }
            }
            return 'follow_any';
        }

        if (!$inSuitMode) {
            return 'free';
        }

        $inSuitCards = Cards::ofSuit($hand, $inSuit);
        if (count($inSuitCards) === 0) {
            return 'free';
        }

        $highInSuitVal = -1;
        foreach ($played as $p) {
            if (Cards::suit($p['card']) === $inSuit) {
                $highInSuitVal = max($highInSuitVal, Cards::value($p['card']));
            }
        }
        if ($highInSuitVal < 0) {
            return 'insuit_any';
        }
        foreach ($inSuitCards as $c) {
            if (Cards::value($c) > $highInSuitVal) {
                return 'insuit_beat';
            }
        }
        return 'insuit_any';
    }

    /**
     * Index into $played of the winning play. $played must be a complete trick
     * (one entry per seat), but any non-empty prefix also resolves sensibly.
     */
    public static function winnerIndex(array $played, string $inSuit): int
    {
        if (count($played) === 0) {
            throw new InvalidArgumentException('Empty trick has no winner.');
        }
        $played     = array_values($played);
        $ledSuit    = Cards::suit($played[0]['card']);
        $inSuitMode = ($inSuit !== 'NS');

        $bestIdx = 0;
        $bestVal = -1;
        $bestIsInSuit = false;

        foreach ($played as $i => $p) {
            $suit = Cards::suit($p['card']);
            $val  = Cards::value($p['card']);
            $isInSuit = ($inSuitMode && $suit === $inSuit);

            if (!$isInSuit && $suit !== $ledSuit) {
                continue; // off-suit sluff can never win
            }

            if ($isInSuit && !$bestIsInSuit) {
                $bestIdx = $i; $bestVal = $val; $bestIsInSuit = true;
                continue;
            }
            if ($isInSuit === $bestIsInSuit && $val > $bestVal) {
                $bestIdx = $i; $bestVal = $val;
            }
            // equal value -> earlier play keeps it (no update)
        }

        return $bestIdx;
    }

    public static function winnerSeat(array $played, string $inSuit): int
    {
        return $played[self::winnerIndex($played, $inSuit)]['seat'];
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
