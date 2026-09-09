<?php
declare(strict_types=1);

require_once __DIR__ . '/Cards.php';

// -----------------------------------------------------------------------------
// Auction — one trip around the table, no second round.
//
//  * Bidding opens to the LEFT of the dealer and moves clockwise; the dealer
//    bids last.
//  * On your turn: pass, or name a number STRICTLY above the current high,
//    within [player_count .. 20].
//  * Highest bid wins. Everyone passes -> the dealer is "stuck" at player_count.
//  * Teams: each member bids on their own turn; the won bid belongs to the team.
//
// State is a plain array so it round-trips to JSON / the DB unchanged:
//   [
//     'players'   => int,
//     'dealerSeat'=> int,
//     'min'       => int,   // lowest legal bid (= player_count)
//     'max'       => 20,
//     'order'     => int[],  // seat order for the trip (left-of-dealer .. dealer)
//     'pos'       => int,    // index into order of whose turn it is
//     'high'      => ['seat'=>int,'amount'=>int] | null,
//     'log'       => array,  // one entry per turn: ['seat'=>..,'action'=>..,'amount'=>..]
//     'closed'    => bool,
//   ]
// -----------------------------------------------------------------------------

final class Auction
{
    const BID_MAX = 20;

    public static function start(int $players, int $dealerSeat): array
    {
        $order = [];
        for ($k = 1; $k <= $players; $k++) {
            $order[] = ($dealerSeat + $k) % $players;   // left of dealer first, dealer last
        }
        return [
            'players'    => $players,
            'dealerSeat' => $dealerSeat,
            'min'        => $players,
            'max'        => self::BID_MAX,
            'order'      => $order,
            'pos'        => 0,
            'high'       => null,
            'log'        => [],
            'closed'     => false,
        ];
    }

    public static function isClosed(array $st): bool
    {
        return !empty($st['closed']);
    }

    /** Seat whose turn it is, or null if the auction is closed. */
    public static function currentSeat(array $st): ?int
    {
        if (self::isClosed($st)) {
            return null;
        }
        return $st['order'][$st['pos']];
    }

    /**
     * Legal bid amounts still available to the player on turn: [lo, hi] inclusive,
     * or null if the only legal action is to pass (someone already bid the max).
     */
    public static function legalRange(array $st): ?array
    {
        if (self::isClosed($st)) {
            return null;
        }
        $lo = $st['high'] === null ? $st['min'] : $st['high']['amount'] + 1;
        if ($lo > $st['max']) {
            return null;
        }
        return [$lo, $st['max']];
    }

    /**
     * Apply one turn. $action is 'bid' or 'pass'. Returns the new state.
     * Throws DomainException on an illegal turn (wrong seat, out-of-range bid…).
     */
    public static function apply(array $st, int $seat, string $action, ?int $amount = null): array
    {
        if (self::isClosed($st)) {
            throw new DomainException('The auction is closed.');
        }
        $expected = self::currentSeat($st);
        if ($seat !== $expected) {
            throw new DomainException("Not seat {$seat}'s turn to bid (waiting on seat {$expected}).");
        }

        if ($action === 'pass') {
            $st['log'][] = ['seat' => $seat, 'action' => 'pass', 'amount' => null];
        } elseif ($action === 'bid') {
            $range = self::legalRange($st);
            if ($range === null) {
                throw new DomainException('No legal raise available — you must pass.');
            }
            if ($amount === null || $amount < $range[0] || $amount > $range[1]) {
                throw new DomainException("Bid must be between {$range[0]} and {$range[1]}.");
            }
            $st['high'] = ['seat' => $seat, 'amount' => $amount];
            $st['log'][] = ['seat' => $seat, 'action' => 'bid', 'amount' => $amount];
        } else {
            throw new DomainException("Unknown bid action '{$action}'.");
        }

        $st['pos']++;
        if ($st['pos'] >= count($st['order'])) {
            $st['closed'] = true;
        }
        return $st;
    }

    /**
     * Final result. Only valid once closed.
     *   ['winnerSeat'=>int, 'amount'=>int, 'stuck'=>bool]
     * stuck === true means everyone passed and the dealer is forced to player_count.
     */
    public static function result(array $st): array
    {
        if (!self::isClosed($st)) {
            throw new DomainException('Auction not closed yet.');
        }
        if ($st['high'] !== null) {
            return [
                'winnerSeat' => $st['high']['seat'],
                'amount'     => $st['high']['amount'],
                'stuck'      => false,
            ];
        }
        return [
            'winnerSeat' => $st['dealerSeat'],
            'amount'     => $st['min'],
            'stuck'      => true,
        ];
    }
}
