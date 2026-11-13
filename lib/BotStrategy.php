<?php
declare(strict_types=1);

namespace PokerPinochle\Lib;

use PokerPinochle\Engine\{Auction, Cards, Meld, State, Trick};

require_once __DIR__ . '/../engine/autoload.php';

final class BotStrategy
{
    /** Tunables, public so the lab (engine/bot_lab.php) can sweep them. */
    public static $cfg = [
        'ceil_set'    => 2,    // which ceiling table bid() uses (0 widest .. 3 tightest); 0-2 tie in the lab, 3 loses
        'ev_bid'      => 1,   // bid from measured ceilings (see bid()); 0 = the older cover+loosen rule
        'team_loosen' => 3,   // C.4: a side totals ~7 against a minimum of 4 (swept 0..8 in the lab: 3 is the plateau)
        'meld_cap'    => 9,    // A.5.3: bid up to my own meld, capped
        'near'        => 8,    // enemy within this many points of the target -> take the bid
        'starve'      => 1,    // A.4: defender vs NS keeps dirt, pitches counters
        'assume_low'  => 1,    // A.8: a follower gives up his lowest card of the suit
        'lead_extra'  => 1.2,  // expected extra counters in a trick I win or lose
        'feed'        => 1,    // C.5: feed a partner who is certain to win
        'banked'      => 1,    // A.8.8: bid banked -> lead the safest probe
    ];

    /** A tunable: a per-view override (profile "l14:starve=0") wins over the default. */
    private static function c(array $v, string $key)
    {
        return $v['cfg'][$key] ?? self::$cfg[$key];
    }

    // ---------------------------------------------------------------- the view

    /** @param array<string,mixed> $cfgOverride per-seat tunable overrides (lab only) */
    public static function view(State $g, int $seat, array $cfgOverride = []): array
    {
        $h = $g->hand;
        $tricks = [];
        foreach ($h['tricks'] as $t) {
            $tricks[] = ['leaderSeat' => $t['leaderSeat'], 'plays' => $t['plays'], 'winnerSeat' => $t['winnerSeat']];
        }
        $sizes = [];
        foreach ($h['hands'] as $s => $cards) {
            $sizes[(int) $s] = count($cards);          // card counts are public
        }
        return [
            'seat'    => $seat,
            'side'    => $g->seatSide($seat),
            'players' => $g->players,
            'mode'    => $g->mode,
            'hand'    => array_values($h['hands'][$seat]),
            'dealer'  => $h['dealerSeat'],
            'auction' => $h['auction'],
            'inSuit'  => $h['inSuit'],
            'bidSeat' => $h['bidWinnerSeat'],
            'bidSide' => $h['bidWinnerSideNo'],
            'bid'     => $h['winningBid'],
            'running' => $g->running,
            'target'  => $g->target,
            'keepMin' => (int) $g->rules['keep_min'],
            'tricks'  => $tricks,
            'trick'   => $h['trick'] ? $h['trick']['plays'] : [],
            'sizes'   => $sizes,
            'meldBySide' => $h['meldBySide'],           // public once play starts
            'cfg'     => $cfgOverride,
        ];
    }

    private static function sideOf(array $v, int $seat): int
    {
        return $v['mode'] === 'teams' ? $seat % 2 : $seat;
    }

    private static function countRank(array $hand, string $rank): int
    {
        $n = 0;
        foreach ($hand as $c) {
            if (Cards::rank($c) === $rank) {
                $n++;
            }
        }
        return $n;
    }

    // --------------------------------------------------------------------- bid

    /** Pre-draw hand features used by the auction: aces, best own meld, and the A.5 chain. */
    public static function strength(array $hand): array
    {
        $aces = self::countRank($hand, 'A');
        $meld = 0;
        foreach (array_merge(Cards::SUITS, ['NS']) as $s) {
            $meld = max($meld, Meld::points($hand, $s));
        }
        $cover = $aces + $meld;                       // sure points
        foreach (Cards::SUITS as $s) {
            if (in_array('T' . $s, $hand, true) && in_array('A' . $s, $hand, true)) {
                $cover++;                              // one ten-gamble backed by its ace
                break;
            }
        }
        foreach (Cards::SUITS as $s) {                 // A.5 long-suit bid: 4+ cards with the ace
            if (count(Cards::ofSuit($hand, $s)) >= 4 && in_array('A' . $s, $hand, true)) {
                $cover++;
                break;
            }
        }
        return ['aces' => $aces, 'meld' => $meld, 'cover' => $cover];
    }

    /** A.5 chain test, loosened for teams (C.4); take the bid near the target (L14.4). */
    public static function bid(array $v): array
    {
        $pass = ['action' => 'pass', 'amount' => null];
        $a = $v['auction'];
        $range = Auction::legalRange($a);
        if ($range === null) {
            return $pass;
        }
        $lo = $range[0];
        $hi = $range[1];
        $mySide = $v['side'];

        // never raise my partner's winning bid
        if ($a['high'] !== null && self::sideOf($v, $a['high']['seat']) === $mySide) {
            return $pass;
        }
        // dealer, everyone passed: stuck at the minimum for free, so pass (A.1.12)
        if ($a['high'] === null && $v['seat'] === $a['dealerSeat']) {
            return $pass;
        }

        $hand = $v['hand'];
        $st = self::strength($hand);
        $aces = $st['aces'];
        $meld = $st['meld'];
        $cover = $st['cover'];
        // L14 measured bid ceilings (4-player teams, lab: engine/bot_lab.php + EV table in the review).
        // Winning the auction at the minimum beats defending at EVERY hand strength, and a higher
        // number never pays unless someone outbids me: raise by exactly 1, up to this ceiling, where
        // E[score diff if I bid at b] still beats E[score diff if I defend].
        if ((int) self::c($v, 'ev_bid') === 1 && $v['mode'] === 'teams' && $v['players'] === 4) {
            $sets = [
                0 => [0 => 6, 1 => 6, 2 => 7, 3 => 8, 4 => 9, 5 => 10, 6 => 10],  // any positive edge over defending
                1 => [0 => 5, 1 => 5, 2 => 7, 3 => 7, 4 => 9, 5 => 9, 6 => 10],   // edge of at least 1 point
                2 => [0 => 4, 1 => 4, 2 => 5, 3 => 6, 4 => 7, 5 => 8, 6 => 9],    // conservative
                3 => [0 => 4, 1 => 4, 2 => 4, 3 => 5, 4 => 6, 5 => 7, 6 => 8],    // very conservative
            ];
            $ceil = $sets[(int) self::c($v, 'ceil_set')];
            $ceiling = $ceil[min($cover, 6)];
            return $lo <= $ceiling ? ['action' => 'bid', 'amount' => $lo] : $pass;
        }
        $loosen = $v['mode'] === 'teams' ? (int) self::c($v, 'team_loosen') : 0;

        $enemyNear = false;
        foreach ($v['running'] as $side => $pts) {
            if ((int) $side !== $mySide && $pts + (int) self::c($v, 'near') >=$v['target']) {
                $enemyNear = true;
            }
        }

        if ($cover + $loosen >= $lo) {
            $amt = $lo;
            // A.5.3: first bidder with a meld that covers more may bid up to it
            if ($a['high'] === null && $meld > $lo) {
                $amt = min($meld, $hi, (int) self::c($v, 'meld_cap'));
            }
            return ['action' => 'bid', 'amount' => $amt];
        }
        // L14.4: the enemy is close to winning and I hold an ace: take the bid (denial)
        if ($enemyNear && $aces >= 1 && $lo <= $v['players'] + 1) {
            return ['action' => 'bid', 'amount' => $lo];
        }
        return $pass;
    }

    // ----------------------------------------------------------------- declare

    /** A.6: marriage / long suit with the ace / otherwise no suit. */
    public static function declare(array $v): string
    {
        $hand = $v['hand'];
        $aces = self::countRank($hand, 'A');
        $best = 'NS';
        $bestScore = Meld::points($hand, 'NS') + 0.8 * $aces;
        $maxLen = 0;
        $scores = [];
        foreach (Cards::SUITS as $s) {
            $n = count(Cards::ofSuit($hand, $s));
            $maxLen = max($maxLen, $n);
            $score = Meld::points($hand, $s) + 0.6 * $n;
            if (in_array('A' . $s, $hand, true)) {
                $score += 1.0;
            }
            if (in_array('T' . $s, $hand, true)) {
                $score += 0.3;
            }
            $scores[$s] = $score;
        }
        // spread strength and no marriage: no suit (L14.4 "no clue plus an ace")
        $marriage = false;
        foreach (Cards::SUITS as $s) {
            if (in_array('K' . $s, $hand, true) && in_array('Q' . $s, $hand, true)) {
                $marriage = true;
            }
        }
        if ($maxLen <= 2 && !$marriage) {
            $bestScore += 1.5;
        }
        foreach ($scores as $s => $score) {
            if ($score > $bestScore) {
                $best = $s;
                $bestScore = $score;
            }
        }
        return $best;
    }

    // -------------------------------------------------------------------- draw

    /** A.4: the contract decides; roles belong to the side (C.2). */
    public static function draw(array $v): array
    {
        $hand = $v['hand'];
        $inSuit = $v['inSuit'];
        $isDefender = ($v['side'] !== $v['bidSide']);
        $keep = array_fill(0, count($hand), false);

        $markCards = static function (array $codes) use ($hand, &$keep): void {
            foreach ($codes as $code) {
                foreach ($hand as $i => $c) {
                    if ($c === $code && !$keep[$i]) {
                        $keep[$i] = true;
                        break;
                    }
                }
            }
        };

        // complete melds stay (a marriage, the pinochle): they are points already made
        foreach (Cards::SUITS as $s) {
            $copies = min(Cards::countOf($hand, 'K' . $s), Cards::countOf($hand, 'Q' . $s));
            for ($i = 0; $i < $copies; $i++) {
                $markCards(['K' . $s, 'Q' . $s]);
            }
        }
        $copies = min(Cards::countOf($hand, 'QS'), Cards::countOf($hand, 'JD'));
        for ($i = 0; $i < $copies; $i++) {
            $markCards(['QS', 'JD']);
        }
        // three of a four-of-a-kind in progress
        foreach (['A', 'K', 'Q', 'J'] as $rank) {
            $held = [];
            foreach (Cards::SUITS as $s) {
                if (in_array($rank . $s, $hand, true)) {
                    $held[] = $rank . $s;
                }
            }
            if (count($held) >= 3) {
                $markCards($held);
            }
        }

        if ($inSuit !== 'NS') {
            // trump contract: keep every trump card, pitch every off-suit card (A.4)
            foreach ($hand as $i => $c) {
                if (Cards::suit($c) === $inSuit) {
                    $keep[$i] = true;
                }
            }
        } else {
            // no suit: aces win; a ten only behind its ace (A.4)
            foreach ($hand as $i => $c) {
                $r = Cards::rank($c);
                $s = Cards::suit($c);
                if ($r === 'A') {
                    $keep[$i] = true;
                } elseif ($r === 'T' && in_array('A' . $s, $hand, true)) {
                    $keep[$i] = true;
                } elseif ($isDefender && (int) self::c($v, 'starve') === 1 && !Cards::isCounter($c)) {
                    $keep[$i] = true;     // L14.1 starve a marginal bid: keep the dirt
                }
            }
        }

        $discards = [];
        foreach ($hand as $i => $c) {
            if (!$keep[$i]) {
                $discards[] = $c;
            }
        }
        $max = 5 - $v['keepMin'];
        if (count($discards) > $max) {
            $sorted = Cards::sortStrong($discards);
            $discards = array_slice($sorted, 0, $max);   // drop the strongest candidates only
        }
        return $discards;
    }

    // -------------------------------------------------------------------- play

    public static function play(array $v): string
    {
        $hand = $v['hand'];
        $inSuit = $v['inSuit'];
        $trick = $v['trick'];
        $legal = array_values(array_unique(Trick::legalPlays($hand, $trick, $inSuit)));
        if (count($legal) === 1) {
            return $legal[0];
        }
        $un = self::unseen($v);
        $tr = self::track($v);

        if (count($trick) === 0) {
            return self::lead($v, $legal, $un, $tr);
        }
        return self::follow($v, $legal, $un, $tr);
    }

    /** Enemy seats (everyone not on my side). */
    private static function enemies(array $v): array
    {
        $out = [];
        for ($s = 0; $s < $v['players']; $s++) {
            if (self::sideOf($v, $s) !== $v['side']) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** Has my side banked a bid already (meld + counters >= bid, one trick won)? */
    private static function banked(array $v): bool
    {
        if ($v['side'] !== $v['bidSide']) {
            return false;
        }
        $ctr = 0;
        $won = 0;
        foreach ($v['tricks'] as $t) {
            if (self::sideOf($v, $t['winnerSeat']) === $v['side']) {
                $won++;
                foreach ($t['plays'] as $p) {
                    $ctr += Cards::counterPoints($p['card']);
                }
            }
        }
        $meld = (int) ($v['meldBySide'][$v['side']] ?? 0);
        return $won >= 1 && ($meld + $ctr) >= (int) $v['bid'];
    }

    private static function lead(array $v, array $legal, array $un, array $tr): string
    {
        $enemies = self::enemies($v);
        $banked = (int) self::c($v, 'banked') === 1 && self::banked($v);
        $x = (float) self::c($v, 'lead_extra');
        $bestCard = $legal[0];
        $bestScore = -1e9;
        foreach ($legal as $c) {
            $pw = self::pWin($v, $c, $enemies, $un, $tr);
            $ctr = Cards::counterPoints($c);
            if ($banked) {
                // A.8.8: a banked bid -> lead the safest probe, keep the ten to feed
                $score = $pw * 10 - $ctr * 2 - Cards::value($c) * 0.1;
            } else {
                $score = (2 * $pw - 1) * ($ctr + $x) + 0.05 * Cards::value($c);   // power before dirt
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCard = $c;
            }
        }
        return $bestCard;
    }

    private static function follow(array $v, array $legal, array $un, array $tr): string
    {
        $trick = $v['trick'];
        $inSuit = $v['inSuit'];
        $wIdx = Trick::winnerIndex($trick, $inSuit);
        $wSeat = $trick[$wIdx]['seat'];
        $wCard = $trick[$wIdx]['card'];
        $led = Cards::suit($trick[0]['card']);
        $partnerWinning = (self::sideOf($v, $wSeat) === $v['side']);

        // the seats still to play after me
        $leader = $trick[0]['seat'];
        $remaining = [];
        for ($k = count($trick) + 1; $k < $v['players']; $k++) {
            $remaining[] = ($leader + $k) % $v['players'];
        }
        $remEnemies = [];
        foreach ($remaining as $s) {
            if (self::sideOf($v, $s) !== $v['side']) {
                $remEnemies[] = $s;
            }
        }

        // which legal cards win the trick right now
        $winners = [];
        foreach ($legal as $c) {
            $test = $trick;
            $test[] = ['seat' => $v['seat'], 'card' => $c];
            if (Trick::winnerIndex($test, $inSuit) === count($trick)) {
                $winners[] = $c;
            }
        }
        $byValueAsc = static function (array $cards): array {
            $s = Cards::sortStrong($cards);
            return array_reverse($s);
        };

        if ($winners) {
            // forced to beat (the engine only offers beaters): win as cheaply as possible
            return $byValueAsc($winners)[0];
        }

        // I cannot win. Is my partner certain to?
        if ($partnerWinning) {
            $certain = true;
            if (!($inSuit === 'NS' && Cards::rank($wCard) === 'A' && Cards::suit($wCard) === $led)) {
                foreach ($remEnemies as $e) {
                    if (self::pBeat($v, $e, $wCard, $led, $un, $tr) > 1e-9) {
                        $certain = false;
                        break;
                    }
                }
            }
            if ($certain && (int) self::c($v, 'feed') === 1) {
                // C.5 feed: give him a counter, a ten or king before an ace
                $counters = array_values(array_filter($legal, [Cards::class, 'isCounter']));
                $nonAce = array_values(array_filter($counters, static function ($c) {
                    return Cards::rank($c) !== 'A';
                }));
                $pool = $nonAce ?: $counters;
                if ($pool) {
                    return self::sluffOrder($v, $pool, $un, $tr)[0];
                }
            }
        }
        return self::sluffOrder($v, $legal, $un, $tr)[0];
    }

    /** Best sluff first: non-counters, then the card least useful as a later lead. */
    private static function sluffOrder(array $v, array $cards, array $un, array $tr): array
    {
        $enemies = self::enemies($v);
        $rows = [];
        foreach ($cards as $c) {
            $rows[] = [
                'c'   => $c,
                'ctr' => Cards::counterPoints($c),
                'pw'  => self::pWin($v, $c, $enemies, $un, $tr),
                'val' => Cards::value($c),
            ];
        }
        usort($rows, static function ($a, $b) {
            if ($a['ctr'] !== $b['ctr']) {
                return $a['ctr'] - $b['ctr'];
            }
            if (abs($a['pw'] - $b['pw']) > 1e-9) {
                return $a['pw'] < $b['pw'] ? -1 : 1;
            }
            return $a['val'] - $b['val'];
        });
        return array_map(static function ($r) { return $r['c']; }, $rows);
    }

    // ------------------------------------------------ what I can know (public)

    /** code => copies not in my hand and not yet played. */
    private static function unseen(array $v): array
    {
        $cnt = [];
        foreach (Cards::SUITS as $s) {
            foreach (Cards::RANKS as $r) {
                $cnt[$r . $s] = 2;
            }
        }
        foreach ($v['hand'] as $c) {
            $cnt[$c]--;
        }
        foreach ($v['tricks'] as $t) {
            foreach ($t['plays'] as $p) {
                $cnt[$p['card']]--;
            }
        }
        foreach ($v['trick'] as $p) {
            $cnt[$p['card']]--;
        }
        return array_filter($cnt, static function ($n) { return $n > 0; });
    }

    /**
     * Voids and value bounds per seat, from the plays alone (A.7, A.8):
     *  - a seat that did not follow the led suit is void in it (and in trump if
     *    he could not trump either);
     *  - a follower who did not beat the best led card could not: nothing higher;
     *  - he then gives up his LOWEST card of the suit, so nothing lower either.
     */
    private static function track(array $v): array
    {
        $void = [];
        $lo = [];
        $hi = [];
        $trump = $v['inSuit'] !== 'NS' ? $v['inSuit'] : null;
        $lists = [];
        foreach ($v['tricks'] as $t) {
            $lists[] = $t['plays'];
        }
        if ($v['trick']) {
            $lists[] = $v['trick'];
        }
        foreach ($lists as $plays) {
            $led = Cards::suit($plays[0]['card']);
            $best = -1;
            foreach ($plays as $i => $p) {
                $seat = $p['seat'];
                $s = Cards::suit($p['card']);
                $val = Cards::value($p['card']);
                if ($i > 0) {
                    if ($s !== $led) {
                        $void[$seat][$led] = true;
                        if ($trump !== null && $s !== $trump) {
                            $void[$seat][$trump] = true;
                        }
                    } elseif ($val <= $best) {
                        $hi[$seat][$led] = min($hi[$seat][$led] ?? 5, $best);
                        if ((int) self::c($v, 'assume_low') === 1) {
                            $lo[$seat][$led] = max($lo[$seat][$led] ?? 0, $val);
                        }
                    }
                }
                if ($s === $led && $val > $best) {
                    $best = $val;
                }
            }
        }
        return ['void' => $void, 'lo' => $lo, 'hi' => $hi];
    }

    private static function possible(array $tr, int $seat, string $code): bool
    {
        $s = Cards::suit($code);
        if (!empty($tr['void'][$seat][$s])) {
            return false;
        }
        $val = Cards::value($code);
        return $val >= ($tr['lo'][$seat][$s] ?? 0) && $val <= ($tr['hi'][$seat][$s] ?? 5);
    }

    /** P(none of K special cards lands in a hand of u drawn from a pool of N). */
    private static function pNone(int $n, int $k, int $u): float
    {
        if ($k <= 0 || $u <= 0 || $n <= 0) {
            return 1.0;
        }
        if ($n - $k < $u) {
            return 0.0;
        }
        $p = 1.0;
        for ($i = 0; $i < $u; $i++) {
            $p *= ($n - $k - $i) / ($n - $i);
        }
        return $p;
    }

    /** P(seat $e beats card $w, when the trick was led in suit $led). */
    private static function pBeat(array $v, int $e, string $w, string $led, array $un, array $tr): float
    {
        $trump = $v['inSuit'] !== 'NS' ? $v['inSuit'] : null;
        $u = (int) ($v['sizes'][$e] ?? 0);
        $wVal = Cards::value($w);
        $wSuit = Cards::suit($w);
        $wIsTrump = ($trump !== null && $wSuit === $trump);
        $pool = 0;
        $kLed = 0;
        $kLedHigher = 0;
        $kTrump = 0;
        $kTrumpHigher = 0;
        foreach ($un as $code => $n) {
            if (!self::possible($tr, $e, $code)) {
                continue;
            }
            $pool += $n;
            $s = Cards::suit($code);
            $val = Cards::value($code);
            if ($s === $led) {
                $kLed += $n;
                if (!$wIsTrump && $val > $wVal) {
                    $kLedHigher += $n;
                }
            }
            if ($trump !== null && $s === $trump) {
                $kTrump += $n;
                if ($wIsTrump && $val > $wVal) {
                    $kTrumpHigher += $n;
                }
            }
        }
        if ($u <= 0 || $pool <= 0) {
            return 0.0;
        }
        $voidLed = !empty($tr['void'][$e][$led]) ? 1.0 : self::pNone($pool, $kLed, $u);
        if ($wIsTrump) {
            $over = 1.0 - self::pNone($pool, $kTrumpHigher, $u);
            return $led === $trump ? $over : $voidLed * $over;
        }
        $higher = 1.0 - self::pNone($pool, $kLedHigher, $u);
        if ($trump !== null && $led !== $trump) {
            $ruff = $voidLed * (1.0 - self::pNone($pool, $kTrump, $u));
            return 1.0 - (1.0 - $higher) * (1.0 - $ruff);
        }
        return $higher;
    }

    /** P(card $c, led now, wins against every enemy). */
    private static function pWin(array $v, string $c, array $enemies, array $un, array $tr): float
    {
        $led = Cards::suit($c);
        $p = 1.0;
        foreach ($enemies as $e) {
            $p *= 1.0 - self::pBeat($v, $e, $c, $led, $un, $tr);
        }
        return $p;
    }
}
