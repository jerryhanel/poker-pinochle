<?php
declare(strict_types=1);

require_once __DIR__ . '/../../engine/autoload.php';
require_once __DIR__ . '/../Assert.php';

use PokerPinochle\Engine\{Deck, State};
use PokerPinochle\Lib\{BotPlayer, BotStrategy};

// =============================================================================
// BotPlayer — l14 -> v1 dispatch and fallback
//
// BotStrategy is declared `final`, so it can't be stubbed with an anonymous
// subclass, and BotPlayer's constructor type-hints the concrete class. Instead:
//   - "delegates": compare against the real strategy's own answer for the
//     same view;
//   - "throws": give the strategy an out-of-range ceil_set (99) and turn PHP
//     warnings/notices into ErrorException while the call runs, so the
//     undefined ceiling-table lookup inside BotStrategy::bid() throws (a
//     Notice on 7.4, a Warning on 8.x — both converted). The v1 heuristic
//     never reads the strategy cfg, so it is unaffected;
//   - "never called": the same throwing strategy with profile 'v1' must leave
//     $fallbacks empty, which proves bid() was never entered.
// =============================================================================

$makeState = static function (): State {
    $g = State::rebuild(4, 'teams', [], [0 => 0, 1 => 0, 2 => 0, 3 => 0], 0, 0, null);
    $g->startHand(Deck::shuffle(Deck::fresh(), 4242), 0);
    $g->roster = [
        0 => ['uuid' => 'a', 'name' => 'A', 'isHost' => true,  'isBot' => false, 'connection' => 'connected'],
        1 => ['uuid' => 'b', 'name' => 'B', 'isHost' => false, 'isBot' => true,  'connection' => 'connected'],
        2 => ['uuid' => 'c', 'name' => 'C', 'isHost' => false, 'isBot' => true,  'connection' => 'connected'],
        3 => ['uuid' => 'd', 'name' => 'D', 'isHost' => false, 'isBot' => true,  'connection' => 'connected'],
    ];
    return $g;
};

/** Run $fn with warnings/notices promoted to ErrorException. */
$strictly = static function (callable $fn) {
    set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0): bool {
        throw new ErrorException($msg, 0, $no, $file, $line);
    });
    try {
        return $fn();
    } finally {
        restore_error_handler();
    }
};

test('BotPlayer/setup: live auction hand', function () use ($makeState) {
    $g = $makeState();
    Assert::equal('auction', $g->hand['phase'], 'BotPlayer/setup: live auction hand');
});

test('BotPlayer/fallbacks: starts empty', function () {
    Assert::equal([], (new BotPlayer(new BotStrategy()))->fallbacks, 'BotPlayer/fallbacks: starts empty');
});

test('BotPlayer/decideBid: null and l14 profiles delegate to strategy', function () use ($makeState) {
    $g = $makeState();
    $seat = $g->auctionTurnSeat();
    $strategy = new BotStrategy();
    $bp = new BotPlayer($strategy);
    $expected = $strategy->bid($strategy->view($g, $seat, []));

    $got = $bp->decideBid($g, $seat, null);
    Assert::equal($expected, $got, 'BotPlayer/decideBid: null profile returns the strategy result');
    Assert::equal([], $bp->fallbacks, 'BotPlayer/decideBid: null profile -> no fallback recorded');
    Assert::equal($expected, $bp->decideBid($g, $seat, 'l14'), "BotPlayer/decideBid: 'l14' profile returns the strategy result");
});

test('BotPlayer/decideBid: strategy throws -> v1 fallback', function () use ($makeState, $strictly) {
    $g = $makeState();
    $seat = $g->auctionTurnSeat();
    $bp = new BotPlayer(new BotStrategy(['ceil_set' => 99]));
    $got = $strictly(static function () use ($bp, $g, $seat) {
        return $bp->decideBid($g, $seat, null);
    });
    Assert::true(is_array($got) && array_key_exists('action', $got), 'BotPlayer/decideBid: fallback result has an action key');
    Assert::true(in_array($got['action'], ['bid', 'pass'], true), 'BotPlayer/decideBid: fallback action is bid or pass');
    Assert::true(array_key_exists('amount', $got), 'BotPlayer/decideBid: fallback result has an amount key');
    Assert::equal(1, count($bp->fallbacks), 'BotPlayer/decideBid: strategy failure appended to fallbacks');
    Assert::true(is_string($bp->fallbacks[0] ?? null) && $bp->fallbacks[0] !== '', 'BotPlayer/decideBid: fallback entry is the error message');

    $v1 = (new BotPlayer(new BotStrategy()))->decideBid($g, $seat, 'v1');
    Assert::equal($v1, $got, 'BotPlayer/decideBid: fallback result equals the v1 heuristic');
});

test("BotPlayer/decideBid: 'v1' profile bypasses strategy entirely", function () use ($makeState, $strictly) {
    $g = $makeState();
    $seat = $g->auctionTurnSeat();
    $v1 = (new BotPlayer(new BotStrategy()))->decideBid($g, $seat, 'v1');

    $bp = new BotPlayer(new BotStrategy(['ceil_set' => 99]));
    $got = $strictly(static function () use ($bp, $g, $seat) {
        return $bp->decideBid($g, $seat, 'v1');
    });
    Assert::true(is_array($got) && array_key_exists('action', $got), "BotPlayer/decideBid: 'v1' result has an action key");
    Assert::equal([], $bp->fallbacks, "BotPlayer/decideBid: 'v1' never enters the strategy (no fallback)");
    Assert::equal($v1, $got, "BotPlayer/decideBid: 'v1' returns the heuristic result");
});

test("BotPlayer/decideBid: 'l14:use_bid=0' bypasses strategy", function () use ($makeState, $strictly) {
    $g = $makeState();
    $seat = $g->auctionTurnSeat();
    $v1 = (new BotPlayer(new BotStrategy()))->decideBid($g, $seat, 'v1');

    $bp = new BotPlayer(new BotStrategy(['ceil_set' => 99]));
    $got = $strictly(static function () use ($bp, $g, $seat) {
        return $bp->decideBid($g, $seat, 'l14:use_bid=0');
    });
    Assert::equal([], $bp->fallbacks, "BotPlayer/decideBid: 'l14:use_bid=0' bypasses the strategy");
    Assert::equal($v1, $got, "BotPlayer/decideBid: 'l14:use_bid=0' returns the heuristic result");
});
