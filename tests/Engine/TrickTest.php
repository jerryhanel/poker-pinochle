<?php
declare(strict_types=1);

require_once __DIR__ . '/../../engine/autoload.php';
require_once __DIR__ . '/../Assert.php';

use PokerPinochle\Engine\{Cards, Trick};

// --- legal plays ------------------------------------------------------------
$lead = ['AS', 'KH', '9D'];
Assert::equal(3, count($lead), 'Trick: leader test hand has 3 cards');
Assert::equal(3, count(Trick::legalPlays($lead, [], 'S')), 'Trick: leader may play anything (3 legal)');

Assert::equal(['KS'], Trick::legalPlays(['KS', '9S', 'AH'], [['seat' => 0, 'card' => 'QS']], 'H'), 'Trick: must follow AND beat when able');
Assert::equal(['9S', 'JS'], Trick::legalPlays(['9S', 'JS', 'AH'], [['seat' => 0, 'card' => 'KS']], 'H'), 'Trick: follow but cannot beat -> any card of the led suit');
Assert::equal(['9H', 'AH'], Trick::legalPlays(['9H', 'AH', 'KD'], [['seat' => 0, 'card' => 'QS']], 'H'), 'Trick: void in led suit -> must play in suit');

$pl = [['seat' => 0, 'card' => 'QS'], ['seat' => 1, 'card' => 'KH']];
Assert::equal(['AH'], Trick::legalPlays(['9H', 'AH', 'JC'], $pl, 'H'), 'Trick: in-suit card already down -> must beat it when able');
Assert::equal(['9H'], Trick::legalPlays(['9H', 'JC'], $pl, 'H'), 'Trick: cannot beat the in-suit card -> must still play in suit, not sluff');

Assert::equal(3, count(Trick::legalPlays(['9C', 'JC', 'KD'], [['seat' => 0, 'card' => 'QS']], 'H')), 'Trick: void with no in-suit cards -> sluff anything');
Assert::equal(3, count(Trick::legalPlays(['9C', 'JC', 'KD'], [['seat' => 0, 'card' => 'QS']], 'NS')), 'Trick: no suit in play, void -> sluff anything');

// --- winners ----------------------------------------------------------------
$full = [
    ['seat' => 0, 'card' => 'KS'],
    ['seat' => 1, 'card' => 'AS'],
    ['seat' => 2, 'card' => '9S'],
    ['seat' => 3, 'card' => 'QD'],
];
Assert::equal(1, Trick::winnerSeat($full, 'H'), 'Trick: highest card of the led suit wins');

$full2 = [
    ['seat' => 0, 'card' => 'AS'],
    ['seat' => 1, 'card' => '9H'],
    ['seat' => 2, 'card' => 'TS'],
    ['seat' => 3, 'card' => 'KH'],
];
Assert::equal(3, Trick::winnerSeat($full2, 'H'), 'Trick: an in-suit card beats the top of the led suit');

$tie = [
    ['seat' => 0, 'card' => 'AS'],
    ['seat' => 1, 'card' => 'AS'],
    ['seat' => 2, 'card' => '9S'],
    ['seat' => 3, 'card' => 'KS'],
];
Assert::equal(0, Trick::winnerSeat($tie, 'H'), 'Trick: identical top cards -> the earlier play keeps it');

// --- counters ---------------------------------------------------------------
Assert::equal(2, Trick::counters($full), 'Trick: counters in trick = 2 (K + A; Q and 9 are not counters)');
Assert::equal(3, Trick::counters($full2), 'Trick: counters in trick = 3 (A + T + K)');
Assert::equal(
    Cards::countCounters(array_column($full2, 'card')),
    Trick::counters($full2),
    'Trick: counters() agrees with Cards::countCounters over the played cards'
);

Assert::throws(function () {
    Trick::winnerIndex([], 'S');
}, 'InvalidArgumentException', 'Trick: winnerIndex of an empty trick throws');
