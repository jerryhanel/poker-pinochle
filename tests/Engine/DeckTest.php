<?php
declare(strict_types=1);

require_once __DIR__ . '/../../engine/autoload.php';
require_once __DIR__ . '/../Assert.php';

use PokerPinochle\Engine\{Cards, Deck};

$fresh = Deck::fresh();
Assert::equal(48, count($fresh), 'Deck: fresh deck is 48 cards');
Assert::equal(24, Cards::countCounters($fresh), 'Deck: fresh deck holds 24 counter points');

$counts = array_count_values($fresh);
Assert::equal(24, count($counts), 'Deck: 24 distinct cards');
Assert::equal(2, min($counts), 'Deck: every card appears at least twice');
Assert::equal(2, max($counts), 'Deck: every card appears at most twice');

$s1 = Deck::shuffle($fresh, 42);
$s2 = Deck::shuffle($fresh, 42);
Assert::equal($s1, $s2, 'Deck: seeded shuffle is deterministic');
Assert::notEqual($fresh, $s1, 'Deck: seeded shuffle reorders the deck');
Assert::equal(24, count(array_count_values($s1)), 'Deck: shuffle preserves the multiset');

$deal = Deck::deal($s1, 4, 5);
Assert::equal(4, count($deal['hands']), 'Deck: 4-player deal yields 4 hands');
foreach ($deal['hands'] as $i => $hh) {
    Assert::equal(5, count($hh), "Deck: 4-player deal hand {$i} has 5 cards");
}
Assert::equal(28, count($deal['stock']), 'Deck: 4-player deal leaves 28 in stock');

$all = $deal['stock'];
foreach ($deal['hands'] as $hh) {
    $all = array_merge($all, $hh);
}
$dealtCounts = array_count_values($all);
$deckCounts  = array_count_values($s1);
ksort($dealtCounts);
ksort($deckCounts);
Assert::equal($deckCounts, $dealtCounts, 'Deck: deal conserves every card');

Assert::throws(function () use ($s1) {
    Deck::deal($s1, 1);
}, 'InvalidArgumentException', 'Deck: deal with 1 player throws (need at least 2)');

Assert::throws(function () use ($s1) {
    Deck::deal($s1, 4, 13);
}, 'InvalidArgumentException', 'Deck: deal of 4x13 throws (deck too small)');
