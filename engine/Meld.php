<?php
declare(strict_types=1);

require_once __DIR__ . '/Cards.php';

// -----------------------------------------------------------------------------
// Meld — scoring combinations shown from the final 5-card hand (after the draw).
// Melded cards STAY in the hand and are played normally during the tricks.
//
// The list is complete as written — no A-T-K-Q-J run, no doubles (double
// pinochle etc.). ONE card may fill TWO different melds at once (e.g. Q♠ counts
// in a spade marriage AND the pinochle), so detection never "consumes" cards.
//
//   Marriage (K+Q same suit) ...... 4 in trump, else 2   (under NT: always 2)
//   9 of trump .................... 1                     (none under NT)
//   Pinochle (Q♠ + J♦) ............ 4
//   Four Aces  (one per suit) ..... 10
//   Four Kings (one per suit) ..... 8
//   Four Queens (one per suit) .... 6
//   Four Jacks  (one per suit) .... 4
//
// $trump is 'S' | 'H' | 'D' | 'C' | 'NT'.
// -----------------------------------------------------------------------------

final class Meld
{
    /**
     * @return array{items: array<int,array{type:string,label:string,cards:array,points:int}>, total:int}
     */
    public static function detect(array $hand, string $trump): array
    {
        $items = [];
        $has = static function (string $card) use ($hand): bool {
            return in_array($card, $hand, true);
        };

        // --- marriages (one per suit, regardless of holding both copies) ---
        foreach (Cards::SUITS as $s) {
            if ($has('K' . $s) && $has('Q' . $s)) {
                $inTrump = ($trump !== 'NT' && $s === $trump);
                $items[] = [
                    'type'   => $inTrump ? 'marriage_trump' : 'marriage',
                    'label'  => ($inTrump ? 'Royal marriage ' : 'Marriage ') . Cards::SUIT_GLYPH[$s],
                    'cards'  => ['K' . $s, 'Q' . $s],
                    'points' => $inTrump ? 4 : 2,
                ];
            }
        }

        // --- 9 of trump (the "dix") ---
        if ($trump !== 'NT' && $has('9' . $trump)) {
            $items[] = [
                'type'   => 'nine_trump',
                'label'  => '9 of trump',
                'cards'  => ['9' . $trump],
                'points' => 1,
            ];
        }

        // --- pinochle: Q spades + J diamonds ---
        if ($has('QS') && $has('JD')) {
            $items[] = [
                'type'   => 'pinochle',
                'label'  => 'Pinochle',
                'cards'  => ['QS', 'JD'],
                'points' => 4,
            ];
        }

        // --- four of a kind, one of each suit ---
        $fours = [
            ['A', 'four_aces',   'Four aces',   10],
            ['K', 'four_kings',  'Four kings',  8],
            ['Q', 'four_queens', 'Four queens', 6],
            ['J', 'four_jacks',  'Four jacks',  4],
        ];
        foreach ($fours as [$rank, $type, $label, $pts]) {
            $cards = [];
            foreach (Cards::SUITS as $s) {
                if ($has($rank . $s)) {
                    $cards[] = $rank . $s;
                }
            }
            if (count($cards) === 4) {
                $items[] = ['type' => $type, 'label' => $label, 'cards' => $cards, 'points' => $pts];
            }
        }

        $total = 0;
        foreach ($items as $it) {
            $total += $it['points'];
        }
        return ['items' => $items, 'total' => $total];
    }

    /** Just the point total. */
    public static function points(array $hand, string $trump): int
    {
        return self::detect($hand, $trump)['total'];
    }
}
