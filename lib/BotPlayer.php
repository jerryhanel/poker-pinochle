<?php
declare(strict_types=1);

namespace PokerPinochle\Lib;

use DomainException;
use Throwable;
use PokerPinochle\Engine\{Auction, Cards, Meld, State};

require_once __DIR__ . '/../engine/autoload.php';

final class BotPlayer
{
    /** Minimum combined (best-suit meld + 2x counters) strength to open a bid. */
    private const BID_STRENGTH_THRESHOLD = 8;

    // ---- public dispatch ---------------------------------------------------

    /**
     * 'v1'  = the original heuristics below.
     * 'l14' = lib/BotStrategy.php (strategies/level-14.md), which sees only the
     *         bot's own hand and public information. A failure inside it falls
     *         back to v1 so a bot never stalls a table.
     */
    public const DEFAULT_PROFILE = 'l14';

    /** @var string[] errors swallowed by the l14 -> v1 fallback (the lab asserts this stays empty) */
    public static $fallbacks = [];

    /** 'l14' or 'l14:key=val,key=val' (per-seat tunable overrides, used by the lab). */
    private static function isL14(?string $profile, string $phase = ''): bool
    {
        $p = $profile ?? self::DEFAULT_PROFILE;
        if (strpos($p, 'l14') !== 0) {
            return false;
        }
        // lab switch "use_play=0" etc.: that phase falls back to v1 (ablation tests)
        return $phase === '' || strpos($p, 'use_' . $phase . '=0') === false;
    }

    private static function l14View(State $g, int $seat, ?string $profile): array
    {
        $over = [];
        $p = $profile ?? self::DEFAULT_PROFILE;
        $pos = strpos($p, ':');
        if ($pos !== false) {
            foreach (explode(',', substr($p, $pos + 1)) as $pair) {
                $kv = explode('=', $pair, 2);
                if (count($kv) === 2) {
                    $over[$kv[0]] = is_numeric($kv[1]) ? $kv[1] + 0 : $kv[1];
                }
            }
        }
        return BotStrategy::view($g, $seat, $over);
    }

    public static function decideBid(State $g, int $seat, ?string $profile = null): array
    {
        if (self::isL14($profile, 'bid')) {
            try {
                return BotStrategy::bid(self::l14View($g, $seat, $profile));
            } catch (Throwable $e) {
                self::$fallbacks[] = $e->getMessage();   // fall through to v1
            }
        }
        return self::decideBidHeuristic($g, $seat);
    }

    public static function decideDeclareSuit(State $g, int $seat, ?string $profile = null): string
    {
        if (self::isL14($profile, 'declare')) {
            try {
                return BotStrategy::declare(self::l14View($g, $seat, $profile));
            } catch (Throwable $e) {
                self::$fallbacks[] = $e->getMessage();   // fall through to v1
            }
        }
        return self::decideDeclareSuitHeuristic($g, $seat);
    }

    public static function decideDraw(State $g, int $seat, ?string $profile = null): array
    {
        if (self::isL14($profile, 'draw')) {
            try {
                return BotStrategy::draw(self::l14View($g, $seat, $profile));
            } catch (Throwable $e) {
                self::$fallbacks[] = $e->getMessage();   // fall through to v1
            }
        }
        return self::decideDrawHeuristic($g, $seat);
    }

    public static function decidePlay(State $g, int $seat, ?string $profile = null): string
    {
        if (self::isL14($profile, 'play')) {
            try {
                return BotStrategy::play(self::l14View($g, $seat, $profile));
            } catch (Throwable $e) {
                self::$fallbacks[] = $e->getMessage();   // fall through to v1
            }
        }
        return self::decidePlayHeuristic($g, $seat);
    }

    // ---- heuristics ----------------------------------------------------------

    /** @return array{action:string, amount:?int} */
    private static function decideBidHeuristic(State $g, int $seat): array
    {
        $range = Auction::legalRange($g->hand['auction']);
        if ($range === null) {
            return ['action' => 'pass', 'amount' => null];
        }

        $hand = $g->hand['hands'][$seat];
        $strength = 0;
        foreach (array_merge(Cards::SUITS, ['NS']) as $suit) {
            $strength = max($strength, Meld::detect($hand, $suit)['total']);
        }
        $strength += 2 * Cards::countCounters($hand);

        if ($strength >= self::BID_STRENGTH_THRESHOLD) {
            return ['action' => 'bid', 'amount' => $range[0]];
        }
        return ['action' => 'pass', 'amount' => null];
    }

    private static function decideDeclareSuitHeuristic(State $g, int $seat): string
    {
        $hand = $g->hand['hands'][$seat];
        $best = null;
        $bestPoints = -1;
        $bestHeld = -1;
        foreach (Cards::SUITS as $suit) {
            $points = Meld::detect($hand, $suit)['total'];
            $held   = count(Cards::ofSuit($hand, $suit));
            if ($points > $bestPoints || ($points === $bestPoints && $held > $bestHeld)) {
                $best = $suit;
                $bestPoints = $points;
                $bestHeld = $held;
            }
        }
        return ($bestPoints > 0 || $bestHeld >= 2) ? (string) $best : 'NS';
    }

    /** @return string[] cards to discard */
    private static function decideDrawHeuristic(State $g, int $seat): array
    {
        $hand = $g->hand['hands'][$seat];

        // cards that are part of a marriage, the pinochle, or an in-progress
        // four-of-a-kind (>=3 of the same rank already held)
        $protect = [];
        foreach (Cards::SUITS as $suit) {
            if (Cards::countOf($hand, 'K' . $suit) > 0 && Cards::countOf($hand, 'Q' . $suit) > 0) {
                $protect[] = 'K' . $suit;
                $protect[] = 'Q' . $suit;
            }
        }
        if (Cards::countOf($hand, 'QS') > 0 && Cards::countOf($hand, 'JD') > 0) {
            $protect[] = 'QS';
            $protect[] = 'JD';
        }
        foreach (['A', 'K', 'Q', 'J'] as $rank) {
            $held = [];
            foreach (Cards::SUITS as $suit) {
                if (Cards::countOf($hand, $rank . $suit) > 0) {
                    $held[] = $rank . $suit;
                }
            }
            if (count($held) >= 3) {
                $protect = array_merge($protect, $held);
            }
        }
        // aces with a same-suit non-ace "protector" card also held
        foreach (Cards::SUITS as $suit) {
            if (Cards::countOf($hand, 'A' . $suit) === 0) {
                continue;
            }
            $suitMates = array_filter(Cards::ofSuit($hand, $suit), static function ($c) {
                return Cards::rank($c) !== 'A';
            });
            if (!empty($suitMates)) {
                $protect[] = 'A' . $suit;
            }
        }
        $protect = array_values(array_unique($protect));

        $discards = [];
        foreach ($hand as $card) {
            if (!in_array($card, $protect, true)) {
                $discards[] = $card;
            }
        }

        $maxDiscard = 5 - (int) $g->rules['keep_min'];
        if (count($discards) > $maxDiscard) {
            // too much to keep everything "protected" — discard the weakest
            // first (lowest trick-strength) until we're back within budget
            $sorted = Cards::sortStrong($discards);   // high -> low
            $discards = array_slice($sorted, 0, $maxDiscard);
        }
        return $discards;
    }

    private static function decidePlayHeuristic(State $g, int $seat): string
    {
        $snap = $g->snapshot($seat);
        if (!empty($snap['suggestedPlay']['code'])) {
            return $snap['suggestedPlay']['code'];
        }
        $legal = $snap['legalPlays'] ?? [];
        if (empty($legal)) {
            throw new DomainException("Bot in seat {$seat} has no legal play.");
        }
        return $legal[0];
    }

    // ---- shared read-only helper --------------------------------------------

    /**
     * Cards played so far this hand (completed tricks + the in-progress
     * trick), flattened. Reuses exactly the data State::snapshot() already
     * derives capturedBySide from — no new persisted state.
     *
     * @return string[]
     */
    public static function cardsSeen(State $g): array
    {
        $out = [];
        if (!$g->hand) {
            return $out;
        }
        foreach ($g->hand['tricks'] as $t) {
            foreach ($t['plays'] as $p) {
                $out[] = $p['card'];
            }
        }
        if ($g->hand['trick']) {
            foreach ($g->hand['trick']['plays'] as $p) {
                $out[] = $p['card'];
            }
        }
        return $out;
    }
}
