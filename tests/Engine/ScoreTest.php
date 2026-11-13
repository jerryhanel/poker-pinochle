<?php
declare(strict_types=1);

require_once __DIR__ . '/../../engine/autoload.php';
require_once __DIR__ . '/../Assert.php';

use PokerPinochle\Engine\Score;

// --- targets ----------------------------------------------------------------
Assert::equal(50, Score::target('teams', 4), 'Score: teams target, 4 players = 50');
Assert::equal(150, Score::target('teams', 6), 'Score: teams target, 6 players = 150');
Assert::equal(200, Score::target('teams', 8), 'Score: teams target, 8 players = 200');
Assert::equal(75, Score::target('cutthroat', 3), 'Score: cutthroat target, 3 players = 75');
Assert::equal(125, Score::target('cutthroat', 5), 'Score: cutthroat target, 5 players = 125');
Assert::equal(175, Score::target('cutthroat', 7), 'Score: cutthroat target, 7 players = 175');
Assert::throws(function () {
    Score::target('teams', 5);
}, 'InvalidArgumentException', 'Score: no teams target for 5 players');

// --- exact make -------------------------------------------------------------
$r = Score::scoreHand(1, 20, [0 => 4, 1 => 6], [0 => 10, 1 => 14], [0 => 2, 1 => 3]);
Assert::true($r['made'], 'Score: exact make counts as made (>=)');
Assert::equal(20, $r['biddingTotal'], 'Score: exact make bidding total = 20');
Assert::equal(20, $r['sides'][1]['handScore'], 'Score: exact make bidder scores the total (20)');
Assert::equal(14, $r['sides'][0]['handScore'], 'Score: non-bidder scores kept meld (4) + counters (10) = 14');

// --- set --------------------------------------------------------------------
$r = Score::scoreHand(1, 20, [0 => 4, 1 => 9], [0 => 18, 1 => 6], [0 => 4, 1 => 1]);
Assert::false($r['made'], 'Score: short of bid -> set');
Assert::equal(-20, $r['sides'][1]['handScore'], 'Score: set bidder scores -(bid) flat');
Assert::equal(22, $r['sides'][0]['handScore'], 'Score: non-bidder still scores meld + counters when bidder is set (22)');

// --- meld forfeited ---------------------------------------------------------
$r = Score::scoreHand(0, 12, [0 => 8, 1 => 0], [0 => 0, 1 => 0], [0 => 0, 1 => 5]);
Assert::false($r['sides'][0]['meldKept'], 'Score: bidder with no tricks forfeits meld');
Assert::equal(0, $r['biddingTotal'], 'Score: bidder with no tricks has bidding total 0');
Assert::equal(-12, $r['sides'][0]['handScore'], 'Score: bidder with no tricks is set (-12)');

$r = Score::scoreHand(0, 15, [0 => 6, 1 => 8], [0 => 24, 1 => 0], [0 => 5, 1 => 0]);
Assert::equal(0, $r['sides'][1]['handScore'], 'Score: non-bidder with zero tricks forfeits meld, no counters -> 0');

// --- totals / winner --------------------------------------------------------
Assert::equal([0 => 30, 1 => 33], Score::applyToTotals([0 => 30, 1 => 12], [0 => 0, 1 => 21]), 'Score: applyToTotals adds hand scores to running totals');
Assert::equal(1, Score::gameWinner([0 => 48, 1 => 51], 50), 'Score: side at or past target wins');
Assert::equal(null, Score::gameWinner([0 => 48, 1 => 49], 50), 'Score: no side at target -> no winner');
