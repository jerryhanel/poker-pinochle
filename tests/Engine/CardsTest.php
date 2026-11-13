<?php
declare(strict_types=1);

require_once __DIR__ . '/../../engine/autoload.php';
require_once __DIR__ . '/../Assert.php';

use PokerPinochle\Engine\Cards;

// --- rank order -------------------------------------------------------------
Assert::true(Cards::value('AS') > Cards::value('TS'), 'Cards: A outranks T');
Assert::true(Cards::value('TS') > Cards::value('KS'), 'Cards: T outranks K');
Assert::true(Cards::value('KS') > Cards::value('QS'), 'Cards: K outranks Q');
Assert::true(Cards::value('QS') > Cards::value('JS'), 'Cards: Q outranks J');
Assert::true(Cards::value('JS') > Cards::value('9S'), 'Cards: J outranks 9');

// --- counters ---------------------------------------------------------------
Assert::true(Cards::isCounter('AS'), 'Cards: AS is a counter');
Assert::true(Cards::isCounter('TH'), 'Cards: TH is a counter');
Assert::true(Cards::isCounter('KD'), 'Cards: KD is a counter');
Assert::false(Cards::isCounter('QS'), 'Cards: QS is not a counter');
Assert::false(Cards::isCounter('JS'), 'Cards: JS is not a counter');
Assert::false(Cards::isCounter('9S'), 'Cards: 9S is not a counter');
Assert::equal(3, Cards::countCounters(['AS', 'TS', 'KS', 'QS', '9S']), 'Cards: countCounters counts A/T/K only');

// --- removeOne --------------------------------------------------------------
$h = ['AS', 'AS', 'KH'];
Assert::true(Cards::removeOne($h, 'AS'), 'Cards: removeOne returns true when the card is present');
Assert::equal(2, count($h), 'Cards: removeOne shrinks the hand by one');
Assert::equal(1, Cards::countOf($h, 'AS'), 'Cards: removeOne takes only one instance of a duplicate');

// --- ofSuit -----------------------------------------------------------------
Assert::equal(['AS', 'QS'], Cards::ofSuit(['AS', 'KH', 'QS', '9D'], 'S'), 'Cards: ofSuit filters to the given suit, preserving order');

// --- isValid ----------------------------------------------------------------
Assert::true(Cards::isValid('AS'), 'Cards: isValid accepts AS');
Assert::false(Cards::isValid('XX'), 'Cards: isValid rejects XX');
Assert::false(Cards::isValid(''), 'Cards: isValid rejects empty string');
Assert::false(Cards::isValid(42), 'Cards: isValid rejects a non-string');

// --- sortStrong -------------------------------------------------------------
$sorted = Cards::sortStrong(['9S', 'TS', 'KS', 'AS', 'QS', 'JS']);
Assert::equal('AS', $sorted[0], 'Cards: sortStrong puts AS first');
Assert::equal(5, Cards::value($sorted[0]), 'Cards: sortStrong first card has value 5');
Assert::equal('9S', $sorted[count($sorted) - 1], 'Cards: sortStrong puts 9S last');
Assert::equal(0, Cards::value($sorted[count($sorted) - 1]), 'Cards: sortStrong last card has value 0');
