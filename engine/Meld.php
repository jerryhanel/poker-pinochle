<?php
declare(strict_types=1);

namespace PokerPinochle\Engine;

final class Meld
{
    /**
     * @return array{items: array<int,array{type:string,label:string,cards:array,points:int}>, total:int}
     */
    public static function detect(array $hand, string $inSuit): array
    {
        $items = [];
        $has = static function (string $card) use ($hand): bool {
            return in_array($card, $hand, true);
        };
        $count = static function (string $card) use ($hand): int {
            $n = 0;
            foreach ($hand as $c) {
                if ($c === $card) {
                    $n++;
                }
            }
            return $n;
        };

        // --- marriages (K+Q same suit) — one instance per full copy held ---
        foreach (Cards::SUITS as $s) {
            $copies = min($count('K' . $s), $count('Q' . $s));
            if ($copies > 0) {
                $isInSuit = ($inSuit !== 'NS' && $s === $inSuit);
                for ($i = 0; $i < $copies; $i++) {
                    $items[] = [
                        'type'   => $isInSuit ? 'marriage_in_suit' : 'marriage',
                        'label'  => ($isInSuit ? 'Royal marriage ' : 'Marriage ') . Cards::SUIT_GLYPH[$s],
                        'cards'  => ['K' . $s, 'Q' . $s],
                        'points' => $isInSuit ? 4 : 2,
                    ];
                }
            }
        }

        // --- 9 in suit (the "dix") — one instance per copy held ---
        if ($inSuit !== 'NS') {
            $copies = $count('9' . $inSuit);
            for ($i = 0; $i < $copies; $i++) {
                $items[] = [
                    'type'   => 'nine_in_suit',
                    'label'  => '9 in suit',
                    'cards'  => ['9' . $inSuit],
                    'points' => 1,
                ];
            }
        }

        // --- pinochle: Q spades + J diamonds — one instance per full copy ---
        $copies = min($count('QS'), $count('JD'));
        for ($i = 0; $i < $copies; $i++) {
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
    public static function points(array $hand, string $inSuit): int
    {
        return self::detect($hand, $inSuit)['total'];
    }
}
