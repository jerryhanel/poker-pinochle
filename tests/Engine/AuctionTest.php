<?php
declare(strict_types=1);

require_once __DIR__ . '/../../engine/autoload.php';
require_once __DIR__ . '/../Assert.php';

use PokerPinochle\Engine\Auction;

$a = Auction::start(4, 0);
Assert::equal([1, 2, 3, 0], $a['order'], 'Auction: left of dealer first, dealer last');
Assert::equal([4, 20], Auction::legalRange($a), 'Auction: opening range is [players, 20]');

// --- everyone passes -> dealer stuck ----------------------------------------
$x = $a;
$x = Auction::apply($x, 1, 'pass');
$x = Auction::apply($x, 2, 'pass');
$x = Auction::apply($x, 3, 'pass');
$x = Auction::apply($x, 0, 'pass');
Assert::true(Auction::isClosed($x), 'Auction: four passes close the auction');
$r = Auction::result($x);
Assert::true($r['stuck'], 'Auction: all pass -> dealer is stuck');
Assert::equal(0, $r['winnerSeat'], 'Auction: all pass -> dealer (seat 0) wins');
Assert::equal(4, $r['amount'], 'Auction: all pass -> stuck at player count (4)');

// --- raises -----------------------------------------------------------------
$y = Auction::start(4, 0);
$y = Auction::apply($y, 1, 'bid', 5);
Assert::throws(function () use ($y) {
    Auction::apply($y, 2, 'bid', 5);
}, 'DomainException', 'Auction: a raise must be strictly higher than the current bid');

$y = Auction::apply($y, 2, 'bid', 8);
$y = Auction::apply($y, 3, 'pass');
$y = Auction::apply($y, 0, 'bid', 12);
$r = Auction::result($y);
Assert::equal(0, $r['winnerSeat'], 'Auction: highest bidder (seat 0) wins');
Assert::equal(12, $r['amount'], 'Auction: winning amount is the highest bid (12)');
Assert::false($r['stuck'], 'Auction: a real bid is not stuck');

// --- illegal turns ----------------------------------------------------------
Assert::throws(function () {
    $z = Auction::start(4, 0);
    Auction::apply($z, 2, 'pass');
}, 'DomainException', 'Auction: wrong seat cannot act');

Assert::throws(function () {
    $z = Auction::start(4, 0);
    Auction::apply($z, 1, 'bid', 3);
}, 'DomainException', 'Auction: bid below player count rejected');

Assert::throws(function () {
    $z = Auction::start(4, 0);
    Auction::apply($z, 1, 'bid', 21);
}, 'DomainException', 'Auction: bid above 20 rejected');

Assert::throws(function () {
    Auction::result(Auction::start(4, 0));
}, 'DomainException', 'Auction: result() on an open auction throws');

// --- max bid leaves no raise ------------------------------------------------
$m = Auction::start(4, 0);
$m = Auction::apply($m, 1, 'bid', 20);
Assert::equal(null, Auction::legalRange($m), 'Auction: after a bid of 20 no raise is available (legalRange null)');
