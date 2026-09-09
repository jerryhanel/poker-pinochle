<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// Cards — the vocabulary. A card is a 2-char code: rank + suit.
//   rank: 9 T J Q K A   (T = ten; keeps every card 2 chars)
//   suit: S H D C
//   e.g. "AS", "TS", "9H", "QD"
//
// Trick-play rank, high -> low:  A T K Q J 9   (the ten outranks the king)
// Counters: A, T, K  — 1 point each, 24 in the 48-card deck.
// The deck holds TWO identical copies of every card, so a code is not unique;
// hands / piles are plain arrays of codes and may contain duplicates.
// -----------------------------------------------------------------------------

final class Cards
{
    const SUITS    = ['S', 'H', 'D', 'C'];
    const RANKS    = ['9', 'T', 'J', 'Q', 'K', 'A'];   // build order (low -> high)
    const COUNTERS = ['A', 'T', 'K'];

    /** Trick-play strength. Higher wins. 9 < J < Q < K < T < A. */
    const RANK_VALUE = ['9' => 0, 'J' => 1, 'Q' => 2, 'K' => 3, 'T' => 4, 'A' => 5];

    const SUIT_NAME = ['S' => 'spades', 'H' => 'hearts', 'D' => 'diamonds', 'C' => 'clubs', 'NT' => 'no trump'];
    const SUIT_GLYPH = ['S' => '♠', 'H' => '♥', 'D' => '♦', 'C' => '♣', 'NT' => 'NT'];

    public static function rank(string $card): string
    {
        return substr($card, 0, 1);
    }

    public static function suit(string $card): string
    {
        return substr($card, 1, 1);
    }

    public static function value(string $card): int
    {
        return self::RANK_VALUE[self::rank($card)];
    }

    public static function isCounter(string $card): bool
    {
        return in_array(self::rank($card), self::COUNTERS, true);
    }

    public static function counterPoints(string $card): int
    {
        return self::isCounter($card) ? 1 : 0;
    }

    /** Total counter points in a list of cards. */
    public static function countCounters(array $cards): int
    {
        $n = 0;
        foreach ($cards as $c) {
            $n += self::counterPoints($c);
        }
        return $n;
    }

    /** True if $card is a well-formed code. */
    public static function isValid($card): bool
    {
        return is_string($card)
            && strlen($card) === 2
            && isset(self::RANK_VALUE[$card[0]])
            && in_array($card[1], self::SUITS, true);
    }

    /** Remove ONE instance of $card from $hand. Returns true if removed. */
    public static function removeOne(array &$hand, string $card): bool
    {
        $i = array_search($card, $hand, true);
        if ($i === false) {
            return false;
        }
        array_splice($hand, $i, 1);
        return true;
    }

    /** Count instances of $card in $hand. */
    public static function countOf(array $hand, string $card): int
    {
        $n = 0;
        foreach ($hand as $c) {
            if ($c === $card) {
                $n++;
            }
        }
        return $n;
    }

    /** Cards in $hand of a given suit. */
    public static function ofSuit(array $hand, string $suit): array
    {
        $out = [];
        foreach ($hand as $c) {
            if (self::suit($c) === $suit) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** Sort a list of codes by trick strength, high -> low (stable-ish). */
    public static function sortStrong(array $cards): array
    {
        usort($cards, static function ($a, $b) {
            $d = self::value($b) - self::value($a);
            if ($d !== 0) {
                return $d;
            }
            return strcmp($a, $b);
        });
        return $cards;
    }

    public static function label(string $card): string
    {
        return self::rank($card) . self::SUIT_GLYPH[self::suit($card)];
    }
}
