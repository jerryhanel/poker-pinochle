<?php
declare(strict_types=1);

require_once __DIR__ . '/../../engine/autoload.php';
require_once __DIR__ . '/../Assert.php';

use PokerPinochle\Engine\Meld;

Assert::equal(4, Meld::detect(['KS', 'QS', 'AH', 'AD', 'AC'], 'S')['total'], 'Meld: royal marriage in suit = 4');
Assert::equal(2, Meld::detect(['KH', 'QH', 'JS', 'JD', '9C'], 'S')['total'], 'Meld: off-suit marriage = 2');
Assert::equal(1, Meld::detect(['9S', 'KH', 'QD', 'JC', '9H'], 'S')['total'], 'Meld: 9 in suit = 1');
Assert::equal(0, Meld::detect(['9S', 'KH', 'QD', 'JC', '9H'], 'NS')['total'], 'Meld: no 9-in-suit meld under NS');
Assert::equal(2, Meld::detect(['9S', '9S', 'KH', 'QD', 'JC'], 'S')['total'], 'Meld: both 9s in suit = 2');

$m = Meld::detect(['KS', 'KS', 'QS', 'QS', 'AH'], 'S');
Assert::equal(8, $m['total'], 'Meld: double royal marriage = 8');
Assert::equal(2, count($m['items']), 'Meld: double royal marriage is two items');

$m = Meld::detect(['QS', 'QS', 'JD', 'JD', 'AH'], 'S');
Assert::equal(8, $m['total'], 'Meld: double pinochle = 8');
Assert::equal(2, count($m['items']), 'Meld: double pinochle is two items');

Assert::equal(4, Meld::detect(['QS', 'JD', '9S', '9C', 'KH'], 'H')['total'], 'Meld: pinochle = 4');
Assert::equal(10, Meld::detect(['AS', 'AH', 'AD', 'AC', 'JH'], 'S')['total'], 'Meld: four aces = 10');
Assert::equal(8, Meld::detect(['KS', 'KH', 'KD', 'KC', 'JD'], 'H')['total'], 'Meld: four kings = 8');
Assert::equal(4 + 4 + 1, Meld::detect(['KS', 'QS', 'JD', '9S', 'AH'], 'S')['total'], 'Meld: card reuse - QS in royal marriage AND pinochle, plus 9 in suit = 9');
Assert::equal(2, Meld::detect(['KS', 'QS', 'AH', 'AD', 'AC'], 'NS')['total'], 'Meld: under NS the marriage is only 2');

Assert::equal(10, Meld::points(['AS', 'AH', 'AD', 'AC', 'JH'], 'S'), 'Meld: points() returns the total (four aces = 10)');
Assert::equal(
    Meld::detect(['AS', 'AH', 'AD', 'AC', 'JH'], 'S')['total'],
    Meld::points(['AS', 'AH', 'AD', 'AC', 'JH'], 'S'),
    'Meld: points() matches detect()[total]'
);
