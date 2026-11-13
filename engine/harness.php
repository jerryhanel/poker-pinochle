<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// Poker Pinochle — engine harness.  Run on the NAS:
//
//     php engine/harness.php            # run tests, then print one sample hand
//     php engine/harness.php --quiet    # tests only
//     php engine/harness.php --hand     # sample hand only
//
// Exit code is non-zero if any assertion fails.
// -----------------------------------------------------------------------------

require_once __DIR__ . '/autoload.php';

use PokerPinochle\Engine\{Cards, Deck, Auction, Meld, Trick, Score, State};

$args    = array_slice($argv, 1);
$doTests = !in_array('--hand', $args, true);
$doHand  = !in_array('--quiet', $args, true);

$PASS = 0; $FAIL = 0;
function ok($cond, string $msg): void
{
    global $PASS, $FAIL;
    if ($cond) { $PASS++; }
    else { $FAIL++; echo "  FAIL  {$msg}\n"; }
}
function throws(callable $fn, string $msg): void
{
    try { $fn(); ok(false, $msg . ' (expected exception)'); }
    catch (Throwable $e) { ok(true, $msg); }
}
function sec(string $t): void { echo "\n== {$t} ==\n"; }

// ===========================================================================
if ($doTests):

sec('Cards');
ok(Cards::value('AS') > Cards::value('TS'), 'A outranks T');
ok(Cards::value('TS') > Cards::value('KS'), 'T outranks K');
ok(Cards::value('KS') > Cards::value('QS') && Cards::value('QS') > Cards::value('JS') && Cards::value('JS') > Cards::value('9S'), 'K > Q > J > 9');
ok(Cards::isCounter('AS') && Cards::isCounter('TH') && Cards::isCounter('KD'), 'A/T/K are counters');
ok(!Cards::isCounter('QS') && !Cards::isCounter('JS') && !Cards::isCounter('9S'), 'Q/J/9 are not counters');
ok(Cards::countCounters(['AS', 'TS', 'KS', 'QS', '9S']) === 3, 'countCounters');
$h = ['AS', 'AS', 'KH']; ok(Cards::removeOne($h, 'AS') && count($h) === 2 && Cards::countOf($h, 'AS') === 1, 'removeOne takes one instance');
ok(Cards::ofSuit(['AS', 'KH', 'QS', '9D'], 'S') === ['AS', 'QS'], 'ofSuit');

sec('Deck');
$fresh = Deck::fresh();
ok(count($fresh) === 48, 'fresh deck is 48');
ok(Cards::countCounters($fresh) === 24, 'deck holds 24 counter points');
$counts = array_count_values($fresh);
ok(count($counts) === 24 && min($counts) === 2 && max($counts) === 2, 'every card appears exactly twice');
$s1 = Deck::shuffle($fresh, 42);
$s2 = Deck::shuffle($fresh, 42);
ok($s1 === $s2 && $s1 !== $fresh, 'seeded shuffle is deterministic and reorders');
ok(count(array_count_values($s1)) === 24, 'shuffle preserves the multiset');
$deal = Deck::deal($s1, 4, 5);
ok(count($deal['hands']) === 4 && count($deal['hands'][0]) === 5 && count($deal['stock']) === 28, '4-player deal: 4x5 + 28 stock');
$all = $deal['stock'];
foreach ($deal['hands'] as $hh) { $all = array_merge($all, $hh); }
ok(array_count_values($all) == array_count_values($s1), 'deal conserves every card');

sec('Auction');
$a = Auction::start(4, 0);
ok($a['order'] === [1, 2, 3, 0], 'left of dealer first, dealer last');
ok(Auction::legalRange($a) === [4, 20], 'opening range is [players, 20]');
$x = $a;
$x = Auction::apply($x, 1, 'pass');
$x = Auction::apply($x, 2, 'pass');
$x = Auction::apply($x, 3, 'pass');
$x = Auction::apply($x, 0, 'pass');
ok(Auction::isClosed($x), 'four turns closes the auction');
$r = Auction::result($x);
ok($r['stuck'] === true && $r['winnerSeat'] === 0 && $r['amount'] === 4, 'all pass -> dealer stuck at player_count');
$y = Auction::start(4, 0);
$y = Auction::apply($y, 1, 'bid', 5);
throws(function () use ($y) { Auction::apply($y, 2, 'bid', 5); }, 'raise must be strictly higher');
$y = Auction::apply($y, 2, 'bid', 8);
$y = Auction::apply($y, 3, 'pass');
$y = Auction::apply($y, 0, 'bid', 12);
$r = Auction::result($y);
ok($r['winnerSeat'] === 0 && $r['amount'] === 12 && !$r['stuck'], 'highest bid wins');
throws(function () { $z = Auction::start(4, 0); Auction::apply($z, 2, 'pass'); }, 'wrong seat cannot act');
throws(function () { $z = Auction::start(4, 0); Auction::apply($z, 1, 'bid', 3); }, 'bid below player_count rejected');
throws(function () { $z = Auction::start(4, 0); Auction::apply($z, 1, 'bid', 21); }, 'bid above 20 rejected');

sec('Meld');
$m = Meld::detect(['KS', 'QS', 'AH', 'AD', 'AC'], 'S');
ok($m['total'] === 4, 'royal marriage in suit = 4');
$m = Meld::detect(['KH', 'QH', 'JS', 'JD', '9C'], 'S');
ok($m['total'] === 2, 'off-suit marriage = 2');
$m = Meld::detect(['9S', 'KH', 'QD', 'JC', '9H'], 'S');
ok($m['total'] === 1, '9 in suit = 1');
$m = Meld::detect(['9S', 'KH', 'QD', 'JC', '9H'], 'NS');
ok($m['total'] === 0, 'no 9-in-suit meld under NS');
$m = Meld::detect(['9S', '9S', 'KH', 'QD', 'JC'], 'S');
ok($m['total'] === 2, 'both 9s in suit = 2');
$m = Meld::detect(['KS', 'KS', 'QS', 'QS', 'AH'], 'S');
ok($m['total'] === 8 && count($m['items']) === 2, 'double royal marriage = 8 (two items)');
$m = Meld::detect(['QS', 'QS', 'JD', 'JD', 'AH'], 'S');
ok($m['total'] === 8 && count($m['items']) === 2, 'double pinochle = 8 (two items)');
$m = Meld::detect(['QS', 'JD', '9S', '9C', 'KH'], 'H');
ok($m['total'] === 4, 'pinochle = 4');
$m = Meld::detect(['AS', 'AH', 'AD', 'AC', 'JH'], 'S');
ok($m['total'] === 10, 'four aces = 10');
$m = Meld::detect(['KS', 'KH', 'KD', 'KC', 'JD'], 'H');
ok($m['total'] === 8, 'four kings = 8');
$m = Meld::detect(['KS', 'QS', 'JD', '9S', 'AH'], 'S');
ok($m['total'] === 4 + 4 + 1, 'card reuse: QS in marriage AND pinochle, plus 9 in suit = 9');
$m = Meld::detect(['KS', 'QS', 'AH', 'AD', 'AC'], 'NS');
ok($m['total'] === 2, 'under NS the marriage is only 2');

sec('Trick');
ok(count(Trick::legalPlays(['AS', 'KH', '9D'], [], 'S')) === 3, 'leader may play anything');
$played = [['seat' => 0, 'card' => 'QS']];
ok(Trick::legalPlays(['KS', '9S', 'AH'], $played, 'H') === ['KS'], 'must follow AND beat when able');
ok(Trick::legalPlays(['9S', 'JS', 'AH'], [['seat' => 0, 'card' => 'KS']], 'H') === ['9S', 'JS'], 'follow but cannot beat -> any of the suit');
ok(Trick::legalPlays(['9H', 'AH', 'KD'], [['seat' => 0, 'card' => 'QS']], 'H') === ['9H', 'AH'], 'void in led suit -> must play in suit');
$pl = [['seat' => 0, 'card' => 'QS'], ['seat' => 1, 'card' => 'KH']];
ok(Trick::legalPlays(['9H', 'AH', 'JC'], $pl, 'H') === ['AH'], 'in suit already down -> must beat it when able');
ok(Trick::legalPlays(['9H', 'JC'], $pl, 'H') === ['9H'], 'cannot beat it -> must still play the (lower) in-suit card, not sluff');
ok(count(Trick::legalPlays(['9C', 'JC', 'KD'], [['seat' => 0, 'card' => 'QS']], 'H')) === 3, 'void with no suit in hand -> sluff anything');
ok(count(Trick::legalPlays(['9C', 'JC', 'KD'], [['seat' => 0, 'card' => 'QS']], 'NS')) === 3, 'no suit in play: void -> sluff anything');
$full = [['seat' => 0, 'card' => 'KS'], ['seat' => 1, 'card' => 'AS'], ['seat' => 2, 'card' => '9S'], ['seat' => 3, 'card' => 'QD']];
ok(Trick::winnerSeat($full, 'H') === 1, 'highest of the led suit wins');
$full2 = [['seat' => 0, 'card' => 'AS'], ['seat' => 1, 'card' => '9H'], ['seat' => 2, 'card' => 'TS'], ['seat' => 3, 'card' => 'KH']];
ok(Trick::winnerSeat($full2, 'H') === 3, 'a card in suit beats the top of the led suit');
$tie = [['seat' => 0, 'card' => 'AS'], ['seat' => 1, 'card' => 'AS'], ['seat' => 2, 'card' => '9S'], ['seat' => 3, 'card' => 'KS']];
ok(Trick::winnerSeat($tie, 'H') === 0, 'identical top cards: the earlier play keeps it');
ok(Trick::counters($full) === 2, 'counters in the trick (K + A, Q/9 are not counters)');
ok(Trick::counters($full2) === 3, 'counters in the trick (A + T + K)');

sec('Score');
ok(Score::target('teams', 4) === 50 && Score::target('teams', 6) === 150 && Score::target('teams', 8) === 200, 'teams targets');
ok(Score::target('cutthroat', 3) === 75 && Score::target('cutthroat', 5) === 125 && Score::target('cutthroat', 7) === 175, 'cutthroat targets = 25 x players');
$r = Score::scoreHand(1, 20, [0 => 4, 1 => 6], [0 => 10, 1 => 14], [0 => 2, 1 => 3]);
ok($r['made'] === true && $r['biddingTotal'] === 20 && $r['sides'][1]['handScore'] === 20, 'exact make counts (>=), scores the total');
ok($r['sides'][0]['handScore'] === 14, 'non-bidder scores kept meld (4) + captured counters (10)');
$r = Score::scoreHand(1, 20, [0 => 4, 1 => 9], [0 => 18, 1 => 6], [0 => 4, 1 => 1]);
ok($r['made'] === false && $r['sides'][1]['handScore'] === -20, 'short of bid -> set, score -(bid) flat');
ok($r['sides'][0]['handScore'] === 22, 'non-bidder still scores their meld + counters when the bidder is set');
$r = Score::scoreHand(0, 12, [0 => 8, 1 => 0], [0 => 0, 1 => 0], [0 => 0, 1 => 5]);
ok($r['sides'][0]['meldKept'] === false && $r['biddingTotal'] === 0 && $r['sides'][0]['handScore'] === -12, 'bidder wins no trick -> meld forfeited, set');
$r = Score::scoreHand(0, 15, [0 => 6, 1 => 8], [0 => 24, 1 => 0], [0 => 5, 1 => 0]);
ok($r['sides'][1]['handScore'] === 0, 'non-bidder with zero tricks: meld forfeited, no counters -> 0');
$run = Score::applyToTotals([0 => 30, 1 => 12], [0 => 0, 1 => 21]);
ok($run === [0 => 30, 1 => 33], 'applyToTotals');
ok(Score::gameWinner([0 => 48, 1 => 51], 50) === 1 && Score::gameWinner([0 => 48, 1 => 49], 50) === null, 'gameWinner at target');

sec('State — one full hand (seeded, 4p teams)');
$g = new State(4, 'teams');
$g->startHand(Deck::shuffle(Deck::fresh(), 1234), 0);
ok($g->hand['phase'] === 'auction' && $g->auctionTurnSeat() === 1, 'deal -> auction, seat 1 opens');
$g->bid(1, 'bid', 4);
$g->bid(2, 'pass'); $g->bid(3, 'pass'); $g->bid(0, 'pass');
ok($g->hand['phase'] === 'declare' && $g->hand['bidWinnerSeat'] === 1 && $g->hand['bidWinnerSideNo'] === 1, 'auction -> declare, seat 1 / side 1 won at 4');
$g->declareInSuit(1, 'S');
ok($g->hand['phase'] === 'draw' && $g->pendingDiscardSeats() === [0, 1, 2, 3], 'declare -> draw, all four seats pending, no turn order');
foreach ($g->pendingDiscardSeats() as $seat) {
    $g->draw($seat, []);   // everyone stands pat
}
ok($g->hand['phase'] === 'play', 'all drawn -> play');
$playCards = [];
foreach ($g->hand['hands'] as $hh) {
    ok(count($hh) === 5, 'each hand back to 5 before play');
    $playCards = array_merge($playCards, $hh);
}
ok(count($playCards) === 20, '20 cards in hands at the start of play');
$startCounters = Cards::countCounters($playCards);
sort($playCards, SORT_STRING);

$guard = 0;
while ($g->hand['phase'] === 'play' && $guard++ < 40) {
    $seat  = $g->playTurnSeat();
    $legal = $g->legalPlaysFor($seat);
    ok(count($legal) > 0, "seat {$seat} has a legal play");
    $g->play($seat, $legal[0]);
}
ok($g->hand['phase'] === 'scored', 'five tricks played -> scored');
ok(count($g->hand['tricks']) === 5, 'exactly 5 tricks');

$cSum = 0; $tSum = 0; $playedCards = [];
foreach ($g->hand['tricks'] as $t) {
    $cSum += $t['counters'];
    foreach ($t['plays'] as $p) { $playedCards[] = $p['card']; }
}
foreach ($g->hand['tricksWonBySide'] as $tw) { $tSum += $tw; }
sort($playedCards, SORT_STRING);
ok($playedCards === $playCards, 'exactly the 20 dealt-in cards were played (conservation)');
ok($cSum === $startCounters, 'counters captured in tricks = counters that were in the played hands');
ok($cSum === array_sum($g->hand['countersBySide']), 'per-side counter tallies sum to the trick total');
ok($tSum === 5, 'trick wins sum to 5');
foreach ($g->hand['hands'] as $hh) { ok(count($hh) === 0, 'hands empty after play'); }
$res = $g->handResult();
ok($res !== null && isset($res['runningAfter']), 'hand result recorded with running totals');
$bside = $g->hand['bidWinnerSideNo'];
$expected = ($g->hand['tricksWonBySide'][$bside] >= 1 ? $g->hand['meldBySide'][$bside] : 0) + $g->hand['countersBySide'][$bside];
ok($res['biddingTotal'] === $expected, 'bidding total = kept meld + counters captured');

sec('State — serialise / restore mid-hand (Phase 2 round-trip)');
$sr = new State(4, 'teams');
$sr->startHand(Deck::shuffle(Deck::fresh(), 4242), 0);
while ($sr->hand['phase'] === 'auction') { $sr->bid($sr->auctionTurnSeat(), 'pass'); }
$sr->declareInSuit($sr->hand['bidWinnerSeat'], 'H');
foreach ($sr->pendingDiscardSeats() as $seat) { $sr->draw($seat, []); }
while ($sr->hand['phase'] === 'play' && count($sr->hand['tricks']) < 2) {
    $s = $sr->playTurnSeat();
    $sr->play($s, $sr->legalPlaysFor($s)[0]);
}
ok($sr->hand['phase'] === 'play' && count($sr->hand['tricks']) === 2, 'stopped mid-hand after 2 tricks');

// serialise exactly what GameStore stores, then rebuild
$blob = json_encode([
    'players' => $sr->players, 'mode' => $sr->mode, 'rules' => $sr->rules,
    'running' => $sr->running, 'handNo' => $sr->handNo,
    'dealer' => $sr->dealerSeat, 'winner' => $sr->gameWinnerSideNo,
    'hand' => $sr->exportHand(),
]);
$row = json_decode($blob, true);
$rs = State::rebuild($row['players'], $row['mode'], $row['rules'], $row['running'], $row['handNo'], $row['dealer'], $row['winner']);
$rs->importHand($row['hand']);

ok($rs->playTurnSeat() === $sr->playTurnSeat(), 'restored: same seat on turn');
ok($rs->legalPlaysFor($rs->playTurnSeat()) === $sr->legalPlaysFor($sr->playTurnSeat()), 'restored: identical legal plays');

$finish = function (State $x) {
    while ($x->hand['phase'] === 'play') {
        $s = $x->playTurnSeat();
        $x->play($s, $x->legalPlaysFor($s)[0]);
    }
    return $x;
};
$finish($sr);
$finish($rs);
ok($sr->handResult() == $rs->handResult(), 'restored state finishes to an identical result');
ok(json_encode($sr->exportHand()) === json_encode($rs->exportHand()), 'hand state byte-identical after finishing from the restore point');
ok($sr->running == $rs->running, 'running totals match after restore + finish');

sec('State — a game runs to a target');
$g2 = new State(4, 'teams');
$hn = 0;
while (!$g2->isGameOver() && $hn++ < 300) {
    $g2->startHand(Deck::shuffle(Deck::fresh(), 5000 + $hn));
    // autopilot: everyone passes -> the (rotating) dealer is stuck at 4
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
    ok($g2->hand['phase'] === 'scored', "hand {$hn} scored");
}
ok($g2->isGameOver(), 'game reached a winner');
$w = $g2->gameWinnerSideNo;
ok($w !== null && $g2->running[$w] >= $g2->target, "winning side {$w} is at or past {$g2->target}");

echo "\n-------------------------------------------\n";
echo ($FAIL === 0 ? "ALL {$PASS} CHECKS PASSED" : "{$PASS} passed, {$FAIL} FAILED") . "\n";

endif; // $doTests

// ===========================================================================
if ($doHand):

echo "\n\n############  SAMPLE HAND  ############\n";
$g = new State(4, 'teams');
$g->startHand(Deck::shuffle(Deck::fresh(), 20260909), 0);

echo "\nDealt (dealer = seat {$g->hand['dealerSeat']}):\n";
foreach ($g->hand['dealt'] as $seat => $cards) {
    printf("  seat %d (side %d): %s\n", $seat, $g->seatSide($seat), implode(' ', array_map([Cards::class, 'label'], $cards)));
}

// scripted auction: seat 2 buys it for 6
$g->bid(1, 'pass');
$g->bid(2, 'bid', 6);
$g->bid(3, 'pass');
$g->bid(0, 'pass');
$res = Auction::result($g->hand['auction']);
printf("\nAuction: seat %d wins at %d%s\n", $res['winnerSeat'], $res['amount'], $res['stuck'] ? ' (stuck)' : '');

$g->declareInSuit($g->hand['bidWinnerSeat'], 'S');
echo "In suit: " . Cards::SUIT_NAME[$g->hand['inSuit']] . "\n";

// scripted discard round: bid winner pitches its two lowest non-counters, others stand pat.
// No turn order — every seat could go in any order (even simultaneously from
// the client's point of view); the harness just processes them in seat order.
// Nobody's replacements are dealt until the whole table has discarded.
foreach ($g->pendingDiscardSeats() as $seat) {
    if ($seat === $g->hand['bidWinnerSeat']) {
        $hand = $g->hand['hands'][$seat];
        $noncounters = array_values(array_filter($hand, function ($c) { return !Cards::isCounter($c); }));
        usort($noncounters, function ($a, $b) { return Cards::value($a) - Cards::value($b); });
        $pitch = array_slice($noncounters, 0, min(2, count($noncounters)));
        $g->draw($seat, $pitch);
        echo "Discard: seat {$seat} pitches " . implode(' ', array_map([Cards::class, 'label'], $pitch)) . "\n";
    } else {
        $g->draw($seat, []);
        echo "Discard: seat {$seat} stands pat\n";
    }
}
echo "Replacements dealt (all at once, now that everyone has discarded):\n";
foreach ($g->hand['drawRecord'] as $seat => $rec) {
    printf("  seat %d drew: %s\n", $seat, $rec['drawn'] ? implode(' ', array_map([Cards::class, 'label'], $rec['drawn'])) : '(none)');
}

echo "\nMeld:\n";
foreach ($g->hand['meldRecord'] as $seat => $det) {
    $bits = [];
    foreach ($det['items'] as $it) { $bits[] = $it['label'] . " +{$it['points']}"; }
    printf("  seat %d: %s = %d\n", $seat, $bits ? implode(', ', $bits) : '(none)', $det['total']);
}
echo "  by side: " . json_encode($g->hand['meldBySide']) . "\n";

echo "\nPlay:\n";
$trickNo = 0;
while ($g->hand['phase'] === 'play') {
    $seat = $g->playTurnSeat();
    $legal = $g->legalPlaysFor($seat);
    // greedy: dump the highest counter you're allowed to, else the lowest card
    usort($legal, function ($a, $b) {
        $ca = Cards::counterPoints($a); $cb = Cards::counterPoints($b);
        if ($ca !== $cb) { return $cb - $ca; }
        return Cards::value($b) - Cards::value($a);
    });
    $card = $legal[0];
    $isLead = count($g->hand['trick']['plays']) === 0;
    if ($isLead) { $trickNo++; echo "  trick {$trickNo}:"; }
    echo " {$seat}:" . Cards::label($card);
    $g->play($seat, $card);
    if ($g->hand['phase'] !== 'play' || count($g->hand['tricks']) >= $trickNo) {
        $t = $g->hand['tricks'][$trickNo - 1];
        echo "   -> seat {$t['winnerSeat']} takes it ({$t['counters']} ctr)\n";
    }
}

$r = $g->handResult();
echo "\nResult:\n";
printf("  bidding side %d bid %d, %s\n", $r['biddingSideNo'], $r['bid'], $r['made'] ? "MADE {$r['biddingTotal']}" : "SET -{$r['bid']}");
echo "  meld by side:     " . json_encode($g->hand['meldBySide']) . "\n";
echo "  counters by side: " . json_encode($g->hand['countersBySide']) . "\n";
echo "  tricks by side:   " . json_encode($g->hand['tricksWonBySide']) . "\n";
echo "  hand scores:      " . json_encode($r['handScores']) . "\n";
echo "  running total:    " . json_encode($r['runningAfter']) . "  (game to {$g->target})\n";

endif; // $doHand

exit($FAIL === 0 ? 0 : 1);
