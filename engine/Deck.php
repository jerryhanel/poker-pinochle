<?php
declare(strict_types=1);

require_once __DIR__ . '/Cards.php';

// -----------------------------------------------------------------------------
// Deck — build, shuffle, deal.
//
// A fresh deck is 48 codes: two copies of 9 T J Q K A in each of S H D C.
// shuffle() uses random_int() (CSPRNG) in production. Pass an integer $seed for
// a deterministic order in tests / the harness — that path uses mt_rand and is
// NOT for real games.
// -----------------------------------------------------------------------------

final class Deck
{
    const SIZE = 48;

    public static function fresh(): array
    {
        $d = [];
        foreach (Cards::SUITS as $s) {
            foreach (Cards::RANKS as $r) {
                $d[] = $r . $s;
                $d[] = $r . $s;
            }
        }
        return $d;
    }

    /**
     * Fisher–Yates shuffle. Returns a new array; does not mutate the input.
     * @param int|null $seed  null = CSPRNG (real games). int = deterministic (tests only).
     */
    public static function shuffle(array $deck, $seed = null): array
    {
        $n = count($deck);
        if ($seed === null) {
            for ($i = $n - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                $t = $deck[$i]; $deck[$i] = $deck[$j]; $deck[$j] = $t;
            }
            return $deck;
        }

        mt_srand((int) $seed);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $t = $deck[$i]; $deck[$i] = $deck[$j]; $deck[$j] = $t;
        }
        mt_srand();
        return $deck;
    }

    /**
     * Deal $handSize cards to each of $players seats, one at a time around the
     * table (round-robin), then the remainder is the face-down stock.
     * @return array{hands: array<int,array>, stock: array}
     */
    public static function deal(array $deck, int $players, int $handSize = 5): array
    {
        if ($players < 2) {
            throw new InvalidArgumentException('Need at least 2 players.');
        }
        if ($players * $handSize > count($deck)) {
            throw new InvalidArgumentException('Deck too small for that deal.');
        }

        $hands = array_fill(0, $players, []);
        $idx = 0;
        for ($round = 0; $round < $handSize; $round++) {
            for ($p = 0; $p < $players; $p++) {
                $hands[$p][] = $deck[$idx++];
            }
        }
        return ['hands' => $hands, 'stock' => array_slice($deck, $idx)];
    }
}
