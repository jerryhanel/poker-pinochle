<?php
declare(strict_types=1);

require_once __DIR__ . '/../../engine/autoload.php';
require_once __DIR__ . '/../Assert.php';

use PokerPinochle\Engine\{Cards, Deck, State};

// =============================================================================
// Section 1 — one full hand (seeded, 4p teams)
// =============================================================================
$g = new State(4, 'teams');
$g->startHand(Deck::shuffle(Deck::fresh(), 1234), 0);
Assert::equal('auction', $g->hand['phase'], 'State/hand: deal -> auction phase');
Assert::equal(1, $g->auctionTurnSeat(), 'State/hand: seat 1 opens the auction');

$g->bid(1, 'bid', 4);
$g->bid(2, 'pass');
$g->bid(3, 'pass');
$g->bid(0, 'pass');
Assert::equal('declare', $g->hand['phase'], 'State/hand: auction -> declare phase');
Assert::equal(1, $g->hand['bidWinnerSeat'], 'State/hand: seat 1 won the bid');
Assert::equal(1, $g->hand['bidWinnerSideNo'], 'State/hand: side 1 won the bid');

$g->declareInSuit(1, 'S');
Assert::equal('draw', $g->hand['phase'], 'State/hand: declare -> draw phase');
Assert::equal([0, 1, 2, 3], $g->pendingDiscardSeats(), 'State/hand: all four seats pending discard');

foreach ($g->pendingDiscardSeats() as $seat) {
    $g->draw($seat, []);   // everyone stands pat
}
Assert::equal('play', $g->hand['phase'], 'State/hand: all drawn -> play phase');

$playCards = [];
foreach ($g->hand['hands'] as $seat => $hh) {
    Assert::equal(5, count($hh), "State/hand: seat {$seat} has 5 cards before play");
    $playCards = array_merge($playCards, $hh);
}
Assert::equal(20, count($playCards), 'State/hand: 20 cards in hands at start of play');
$startCounters = Cards::countCounters($playCards);
sort($playCards, SORT_STRING);

$guard = 0;
while ($g->hand['phase'] === 'play' && $guard++ < 40) {
    $seat  = $g->playTurnSeat();
    $legal = $g->legalPlaysFor($seat);
    Assert::true(count($legal) > 0, "State/hand: seat {$seat} has a legal play");
    $g->play($seat, $legal[0]);
}
Assert::equal('scored', $g->hand['phase'], 'State/hand: five tricks played -> scored');
Assert::equal(5, count($g->hand['tricks']), 'State/hand: exactly 5 tricks');
foreach ($g->hand['hands'] as $seat => $hh) {
    Assert::equal(0, count($hh), "State/hand: seat {$seat} hand empty after play");
}

$cSum = 0;
$tSum = 0;
$playedCards = [];
foreach ($g->hand['tricks'] as $t) {
    $cSum += $t['counters'];
    foreach ($t['plays'] as $p) {
        $playedCards[] = $p['card'];
    }
}
foreach ($g->hand['tricksWonBySide'] as $tw) {
    $tSum += $tw;
}
sort($playedCards, SORT_STRING);
Assert::equal($playCards, $playedCards, 'State/hand: exactly the 20 dealt-in cards were played (card conservation)');
Assert::equal($startCounters, $cSum, 'State/hand: counters captured in tricks = counters in played hands');
Assert::equal($cSum, array_sum($g->hand['countersBySide']), 'State/hand: per-side counter tallies sum to trick total');
Assert::equal(5, $tSum, 'State/hand: trick wins sum to 5');

$res = $g->handResult();
Assert::true($res !== null, 'State/hand: hand result recorded');
Assert::true(is_array($res) && array_key_exists('runningAfter', $res), 'State/hand: hand result has runningAfter');
$bside = $g->hand['bidWinnerSideNo'];
$expected = ($g->hand['tricksWonBySide'][$bside] >= 1 ? $g->hand['meldBySide'][$bside] : 0)
    + $g->hand['countersBySide'][$bside];
Assert::equal($expected, $res['biddingTotal'], 'State/hand: bidding total = kept meld + counters captured');

// =============================================================================
// Section 2 — serialise / restore mid-hand
// =============================================================================
$sr = new State(4, 'teams');
$sr->startHand(Deck::shuffle(Deck::fresh(), 4242), 0);
while ($sr->hand['phase'] === 'auction') {
    $sr->bid($sr->auctionTurnSeat(), 'pass');
}
$sr->declareInSuit($sr->hand['bidWinnerSeat'], 'H');
foreach ($sr->pendingDiscardSeats() as $seat) {
    $sr->draw($seat, []);
}
while ($sr->hand['phase'] === 'play' && count($sr->hand['tricks']) < 2) {
    $s = $sr->playTurnSeat();
    $sr->play($s, $sr->legalPlaysFor($s)[0]);
}
Assert::equal('play', $sr->hand['phase'], 'State/restore: still in play mid-hand');
Assert::equal(2, count($sr->hand['tricks']), 'State/restore: stopped after 2 tricks');

$blob = json_encode([
    'players' => $sr->players,
    'mode'    => $sr->mode,
    'rules'   => $sr->rules,
    'running' => $sr->running,
    'handNo'  => $sr->handNo,
    'dealer'  => $sr->dealerSeat,
    'winner'  => $sr->gameWinnerSideNo,
    'hand'    => $sr->exportHand(),
]);
$row = json_decode($blob, true);
$rs = State::rebuild(
    $row['players'],
    $row['mode'],
    $row['rules'],
    $row['running'],
    $row['handNo'],
    $row['dealer'],
    $row['winner']
);
$rs->importHand($row['hand']);

Assert::equal($sr->playTurnSeat(), $rs->playTurnSeat(), 'State/restore: same seat on turn');
Assert::equal(
    $sr->legalPlaysFor($sr->playTurnSeat()),
    $rs->legalPlaysFor($rs->playTurnSeat()),
    'State/restore: identical legal plays'
);

$finish = function (State $x): State {
    $steps = 0;
    while ($x->hand['phase'] === 'play' && $steps++ < 40) {
        $s = $x->playTurnSeat();
        $x->play($s, $x->legalPlaysFor($s)[0]);
    }
    return $x;
};
$finish($sr);
$finish($rs);
Assert::true($sr->handResult() == $rs->handResult(), 'State/restore: restored state finishes to an identical result');
Assert::equal(json_encode($sr->exportHand()), json_encode($rs->exportHand()), 'State/restore: hand state byte-identical after finishing');
Assert::true($sr->running == $rs->running, 'State/restore: running totals match after restore + finish');

// =============================================================================
// Section 3 — a game runs to a target
// =============================================================================
$g2 = new State(4, 'teams');
$hn = 0;
$allScored = true;
while (!$g2->isGameOver() && $hn++ < 300) {
    $g2->startHand(Deck::shuffle(Deck::fresh(), 5000 + $hn));
    // autopilot: everyone passes -> the (rotating) dealer is stuck
    while ($g2->hand['phase'] === 'auction') {
        $g2->bid($g2->auctionTurnSeat(), 'pass');
    }
    $g2->declareInSuit($g2->hand['bidWinnerSeat'], 'S');
    foreach ($g2->pendingDiscardSeats() as $seat) {
        $g2->draw($seat, []);
    }
    $steps = 0;
    while ($g2->hand['phase'] === 'play' && $steps++ < 40) {
        $s = $g2->playTurnSeat();
        $g2->play($s, $g2->legalPlaysFor($s)[0]);
    }
    if ($g2->hand['phase'] !== 'scored') {
        $allScored = false;
        Assert::equal('scored', $g2->hand['phase'], "State/game: hand {$hn} scored");
    }
}
Assert::true($allScored, "State/game: every hand scored ({$hn} hands)");
Assert::true($g2->isGameOver(), 'State/game: game reached a winner');
$w = $g2->gameWinnerSideNo;
Assert::true($w !== null, 'State/game: gameWinnerSideNo is set');
Assert::true($w !== null && $g2->running[$w] >= $g2->target, "State/game: winning side is at or past target {$g2->target}");
