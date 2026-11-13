<?php
declare(strict_types=1);

namespace PokerPinochle\Engine;

/**
 * Build, shuffle, and deal a 48-card pinochle deck.
 *
 * shuffle() uses random_int() (CSPRNG) for real games. Pass an integer seed
 * for a deterministic order in tests — that path uses mt_rand and is NOT
 * suitable for real play.
 */
final class Deck
{
    public const SIZE = 48;

    /** @return string[] */
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
     * Fisher-Yates shuffle. Returns a new array; does not mutate the input.
     *
     * @param string[] $deck
     * @param int|null $seed  null = CSPRNG (real games). int = deterministic (tests).
     * @return string[]
     */
    public static function shuffle(array $deck, ?int $seed = null): array
    {
        $n = count($deck);
        if ($seed === null) {
            for ($i = $n - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$deck[$i], $deck[$j]] = [$deck[$j], $deck[$i]];
            }
            return $deck;
        }

        mt_srand($seed);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$deck[$i], $deck[$j]] = [$deck[$j], $deck[$i]];
        }
        mt_srand();
        return $deck;
    }

    /**
     * Deal $handSize cards to each of $players seats (round-robin),
     * then the remainder becomes the stock.
     *
     * @param string[] $deck
     * @return array{hands: array<int, string[]>, stock: string[]}
     */
    public static function deal(array $deck, int $players, int $handSize = 5): array
    {
        if ($players < 2) {
            throw new \InvalidArgumentException('Need at least 2 players.');
        }
        if ($players * $handSize > count($deck)) {
            throw new \InvalidArgumentException('Deck too small for that deal.');
        }

        $hands = array_fill(0, $players, []);
        $idx   = 0;
        for ($round = 0; $round < $handSize; $round++) {
            for ($p = 0; $p < $players; $p++) {
                $hands[$p][] = $deck[$idx++];
            }
        }
        return ['hands' => $hands, 'stock' => array_slice($deck, $idx)];
    }
}
