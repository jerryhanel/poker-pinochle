<?php
declare(strict_types=1);

namespace PokerPinochle\Lib;

final class BotNames
{
    private const NAMES = [
        'Ace McBluff', 'Sandbagger Sal', 'Deuce Coupe', 'Marbles', 'The Ringer',
        'Widow Maker', 'Lucky Nine', 'Stone Cold Stack', 'Grandma Meld',
        'Trickster Jax', 'Sir Counts-a-Lot', 'The Pinochle Kid', 'Bluff Daddy',
        'Queen of Spades', 'Jack of All Melds', 'Slow-Roll Rosie', 'The Dealer\'s Ghost',
        'Cutthroat Clara', 'Barn Burner Bill', 'Table Talk Tommy', 'The Silent Bidder',
        'Reckless Ricky', 'Iron Hand Ida', 'The Underbidder', 'Mad Marriage Mo',
        'Nine of Nothing', 'Stuck Stan', 'The Royal Flusher', 'Fold \'Em Fiona',
        'Big Bid Benny', 'Counters McGee', 'The Card Counter', 'Velvet Hands Vic',
        'Auction Annie', 'The Last Trick', 'Discard Pile Dan', 'Sneaky Suit',
        'The Meld Machine', 'Old Faithful Fred', 'Wildcard Wendy',
    ];

    /** Random pick — never repeats in a predictable order, no state kept. */
    public static function pick(): string
    {
        return self::NAMES[array_rand(self::NAMES)];
    }
}
