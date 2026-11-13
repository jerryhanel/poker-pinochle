<?php
declare(strict_types=1);

namespace PokerPinochle\Engine;

/**
 * Card vocabulary. A card is a 2-char code: rank + suit.
 *
 *   rank: 9 T J Q K A   (T = ten; keeps every card to 2 chars)
 *   suit: S H D C
 *
 * Trick-play strength (high to low): A T K Q J 9 — the ten outranks the king.
 * Counters: A, T, K — 1 point each, 24 in the full 48-card deck.
 * The deck holds TWO identical copies of every card.
 */
final class Cards
{
    public const SUITS    = ['S', 'H', 'D', 'C'];
    public const RANKS    = ['9', 'T', 'J', 'Q', 'K', 'A'];
    public const COUNTERS = ['A', 'T', 'K'];

    public const RANK_VALUE = ['9' => 0, 'J' => 1, 'Q' => 2, 'K' => 3, 'T' => 4, 'A' => 5];

    public const SUIT_NAME  = ['S' => 'spades', 'H' => 'hearts', 'D' => 'diamonds', 'C' => 'clubs', 'NS' => 'no suit'];
    public const SUIT_GLYPH = ['S' => '♠', 'H' => '♥', 'D' => '♦', 'C' => '♣', 'NS' => 'NS'];

    public static function rank(string $card): string
    {
        return $card[0];
    }

    public static function suit(string $card): string
    {
        return $card[1];
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

    /** @param string[] $cards */
    public static function countCounters(array $cards): int
    {
        $n = 0;
        foreach ($cards as $c) {
            $n += self::counterPoints($c);
        }
        return $n;
    }

    /** @param mixed $card */
    public static function isValid($card): bool
    {
        return is_string($card)
            && strlen($card) === 2
            && isset(self::RANK_VALUE[$card[0]])
            && in_array($card[1], self::SUITS, true);
    }

    /**
     * Remove ONE instance of $card from $hand.
     * @param string[] $hand
     */
    public static function removeOne(array &$hand, string $card): bool
    {
        $i = array_search($card, $hand, true);
        if ($i === false) {
            return false;
        }
        array_splice($hand, $i, 1);
        return true;
    }

    /** @param string[] $hand */
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

    /**
     * @param string[] $hand
     * @return string[]
     */
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

    /**
     * Sort by trick strength, high to low.
     * @param string[] $cards
     * @return string[]
     */
    public static function sortStrong(array $cards): array
    {
        usort($cards, static function (string $a, string $b): int {
            return self::value($b) - self::value($a) ?: strcmp($a, $b);
        });
        return $cards;
    }

    public static function label(string $card): string
    {
        $r = self::rank($card);
        return ($r === 'T' ? '10' : $r) . self::SUIT_GLYPH[self::suit($card)];
    }
}
