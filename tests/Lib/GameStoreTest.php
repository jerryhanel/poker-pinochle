<?php
declare(strict_types=1);

require_once __DIR__ . '/../../engine/autoload.php';
require_once __DIR__ . '/../Assert.php';
require_once __DIR__ . '/../FakeMysqli.php';

use PokerPinochle\Engine\State;
use PokerPinochle\Lib\GameStore;

// GameStore.php requires lib/db.php -> lib/bootstrap.php, which reads
// config.php and starts a session. Under the CLI runner earlier tests have
// already printed output, so the session calls only emit "headers already
// sent" warnings — load the class with those muted.
$prevLevel = error_reporting(error_reporting() & ~E_WARNING);
class_exists(GameStore::class);
error_reporting($prevLevel);

$makeState = static function (): State {
    return State::rebuild(4, 'teams', [], [0 => 0, 1 => 0, 2 => 0, 3 => 0], 0, 0, null);
};

$roster = [
    0 => ['uuid' => 'u0', 'name' => 'Ann', 'isHost' => true,  'isBot' => false, 'connection' => 'connected'],
    1 => ['uuid' => 'u1', 'name' => 'Bot', 'isHost' => false, 'isBot' => true,  'connection' => 'connected'],
    2 => ['uuid' => 'u2', 'name' => 'Cal', 'isHost' => false, 'isBot' => false, 'connection' => 'away'],
];

test('seat shape with no roster', function () use ($makeState) {
    $store = new GameStore(new FakeMysqli());
    $snap = $store->snapshotFor($makeState(), null);
    Assert::equal(4, count($snap['seats']), 'GameStore/snapshotFor: 4 seats for a 4-player game');
    $seatKeys = true;
    foreach ($snap['seats'] as $s) {
        if (!array_key_exists('seat', $s)) {
            $seatKeys = false;
        }
    }
    Assert::true($seatKeys, 'GameStore/snapshotFor: every seat has a seat key');
    Assert::false(
        array_key_exists('uuid', $snap['seats'][0]) && $snap['seats'][0]['uuid'] !== null,
        'GameStore/snapshotFor: no roster -> no uuid merged'
    );
});

test('roster merged into seats', function () use ($makeState, $roster) {
    $store = new GameStore(new FakeMysqli());
    $g = $makeState();
    $g->roster = $roster;
    $snap = $store->snapshotFor($g, null);
    foreach ($roster as $seat => $p) {
        $merged = true;
        foreach (['uuid', 'name', 'isHost', 'isBot', 'connection'] as $k) {
            if (($snap['seats'][$seat][$k] ?? '__missing__') !== $p[$k]) {
                $merged = false;
            }
        }
        Assert::true($merged, "GameStore/snapshotFor: roster merged into seat {$seat}");
    }
    Assert::equal(4, count($snap['seats']), 'GameStore/snapshotFor: partial roster keeps all 4 seats');
});

test('viewer resolution', function () use ($makeState, $roster) {
    $store = new GameStore(new FakeMysqli());
    $g = $makeState();
    $g->roster = $roster;

    $snap = $store->snapshotFor($g, 'stranger');
    Assert::true(array_key_exists('viewerSeat', $snap), 'GameStore/snapshotFor: viewerSeat key present');
    Assert::equal(null, $snap['viewerSeat'], 'GameStore/snapshotFor: unknown viewer -> viewerSeat null');
    Assert::equal(null, $store->snapshotFor($g, null)['viewerSeat'], 'GameStore/snapshotFor: null viewer -> viewerSeat null');

    Assert::equal(0, $store->snapshotFor($g, 'u0')['viewerSeat'], 'GameStore/snapshotFor: viewer u0 -> seat 0');
    Assert::equal(2, $store->snapshotFor($g, 'u2')['viewerSeat'], 'GameStore/snapshotFor: viewer u2 -> seat 2');
});

test('requireGameRow returns row and id', function () {
    $db = new FakeMysqli();
    $db->addRows([['id' => 7, 'game_uuid' => 'abc', 'mode' => 'teams', 'status' => 'lobby']]);
    $store = new GameStore($db);
    ['id' => $id, 'row' => $row] = $store->requireGameRow('abc');
    Assert::equal(7, $id, 'GameStore/requireGameRow: returns int id');
    Assert::equal('abc', $row['game_uuid'], 'GameStore/requireGameRow: row has game_uuid');
    Assert::equal(['abc'], $db->getParams()[0], 'GameStore/requireGameRow: binds uuid param');
});

test('requireGameRow throws when not found', function () {
    $db = new FakeMysqli();
    $db->addRows([]);
    $store = new GameStore($db);
    Assert::throws(static function () use ($store) {
        $store->requireGameRow('nope');
    }, 'DomainException', 'GameStore/requireGameRow: throws DomainException when game not found');
});

test('findGameId returns int or null', function () {
    $db = new FakeMysqli();
    $db->addRows([['id' => 5]]);
    $db->addRows([]);
    $store = new GameStore($db);
    Assert::equal(5, $store->findGameId('found'), 'GameStore/findGameId: returns id when found');
    Assert::equal(null, $store->findGameId('missing'), 'GameStore/findGameId: returns null when not found');
});

test('findGameRowByCode returns row or null', function () {
    $db = new FakeMysqli();
    $db->addRows([['id' => 3, 'game_uuid' => 'xyz', 'join_code' => 'ABC']]);
    $db->addRows([]);
    $store = new GameStore($db);
    $row = $store->findGameRowByCode('ABC');
    Assert::equal(3, (int) $row['id'], 'GameStore/findGameRowByCode: returns row when found');
    Assert::equal(['ABC'], $db->getParams()[0], 'GameStore/findGameRowByCode: binds code param');
    Assert::equal(null, $store->findGameRowByCode('XXX'), 'GameStore/findGameRowByCode: null when not found');
});

test('playerSeat returns seat row or null', function () {
    $db = new FakeMysqli();
    $db->addRows([['seat_no' => 2, 'side_no' => 0]]);
    $db->addRows([]);
    $store = new GameStore($db);
    $mine = $store->playerSeat(10, 'user-uuid');
    Assert::equal(2, (int) $mine['seat_no'], 'GameStore/playerSeat: returns seat_no when found');
    Assert::equal([10, 'user-uuid'], $db->getParams()[0], 'GameStore/playerSeat: binds gameId and uuid');
    Assert::equal(null, $store->playerSeat(10, 'other'), 'GameStore/playerSeat: null when not found');
});

test('countSeated and countConnected', function () {
    $db = new FakeMysqli();
    $db->addRows([['c' => 3]]);
    $db->addRows([['c' => 2]]);
    $store = new GameStore($db);
    Assert::equal(3, $store->countSeated(42), 'GameStore/countSeated: returns count');
    Assert::equal([42], $db->getParams()[0], 'GameStore/countSeated: binds gameId');
    Assert::equal(2, $store->countConnected(42), 'GameStore/countConnected: returns count');
    Assert::true(
        strpos($db->getSql()[1] ?? '', "connection != 'left'") !== false,
        'GameStore/countConnected: SQL filters out left players'
    );
});

test('takenSeats returns int array', function () {
    $db = new FakeMysqli();
    $db->addRows([['seat_no' => '0'], ['seat_no' => '2'], ['seat_no' => '3']]);
    $store = new GameStore($db);
    Assert::equal([0, 2, 3], $store->takenSeats(5), 'GameStore/takenSeats: returns int array of seat numbers');
    Assert::equal([5], $db->getParams()[0], 'GameStore/takenSeats: binds gameId');
});

test('write-only mutation methods issue correct SQL', function () {
    $db = new FakeMysqli();
    $store = new GameStore($db);

    $store->removePlayer(1, 'u1');
    Assert::true(strpos($db->getSql()[0] ?? '', 'DELETE FROM game_player') !== false, 'GameStore/removePlayer: issues DELETE FROM game_player');
    Assert::equal([1, 'u1'], $db->getParams()[0], 'GameStore/removePlayer: binds gameId and uuid');

    $store->markPlayerLeft(1, 'u1');
    Assert::true(strpos($db->getSql()[1] ?? '', "connection = 'left'") !== false, 'GameStore/markPlayerLeft: SQL sets connection to left');

    $store->removeSeat(2, 3);
    Assert::true(strpos($db->getSql()[2] ?? '', 'seat_no') !== false, 'GameStore/removeSeat: SQL references seat_no');
    Assert::equal([2, 3], $db->getParams()[2], 'GameStore/removeSeat: binds gameId and seatNo');

    $store->deleteGame(99);
    Assert::true(strpos($db->getSql()[3] ?? '', 'DELETE FROM game') !== false, 'GameStore/deleteGame: issues DELETE FROM game');

    $store->abandonGame(99);
    Assert::true(strpos($db->getSql()[4] ?? '', 'abandoned') !== false, 'GameStore/abandonGame: SQL sets status to abandoned');

    $store->clearVotes(5);
    Assert::true(strpos($db->getSql()[5] ?? '', 'team_vote_json = NULL') !== false, 'GameStore/clearVotes: NULLs team_vote_json');
    Assert::true(strpos($db->getSql()[5] ?? '', 'bot_votes_json') === false, 'GameStore/clearVotes: does not touch bot_votes_json by default');

    $store->clearVotes(5, true);
    Assert::true(strpos($db->getSql()[6] ?? '', 'bot_votes_json = NULL') !== false, 'GameStore/clearVotes(true): also NULLs bot_votes_json');
});

test('latestHandRow returns hand row or null', function () {
    $db = new FakeMysqli();
    $db->addRows([['id' => 11, 'phase' => 'auction', 'ready_json' => null]]);
    $db->addRows([]);
    $store = new GameStore($db);
    $hand = $store->latestHandRow(5);
    Assert::equal(11, (int) $hand['id'], 'GameStore/latestHandRow: returns hand row');
    Assert::equal([5], $db->getParams()[0], 'GameStore/latestHandRow: binds gameId');
    Assert::true(strpos($db->getSql()[0] ?? '', 'ORDER BY hand_no DESC') !== false, 'GameStore/latestHandRow: orders by hand_no DESC');
    Assert::equal(null, $store->latestHandRow(5), 'GameStore/latestHandRow: null when no hand found');
});

test('deleteHand and updateReadyList issue correct SQL', function () {
    $db = new FakeMysqli();
    $store = new GameStore($db);

    $store->deleteHand(7);
    Assert::true(strpos($db->getSql()[0] ?? '', 'DELETE FROM hand') !== false, 'GameStore/deleteHand: issues DELETE FROM hand');
    Assert::equal([7], $db->getParams()[0], 'GameStore/deleteHand: binds handId');

    $store->updateReadyList(7, '[0,2]');
    Assert::true(strpos($db->getSql()[1] ?? '', 'ready_json') !== false, 'GameStore/updateReadyList: SQL updates ready_json');
    Assert::equal(['[0,2]', 7], $db->getParams()[1], 'GameStore/updateReadyList: binds json and handId');
});

test('openGames returns rows and uses correct SQL', function () {
    $db = new FakeMysqli();
    $db->addRows([
        ['game_uuid' => 'g1', 'mode' => 'teams', 'player_count' => 4, 'join_code' => 'XY',
         'created_at' => '2026-01-01', 'status' => 'lobby', 'seated' => 2, 'viewers' => 0],
    ]);
    $store = new GameStore($db);
    $rows = $store->openGames();
    Assert::equal(1, count($rows), 'GameStore/openGames: returns one row per scripted game');
    Assert::equal('g1', $rows[0]['game_uuid'] ?? null, 'GameStore/openGames: row has game_uuid');
    Assert::true(
        strpos($db->getSql()[0] ?? '', "status IN ('lobby','active')") !== false,
        'GameStore/openGames: SQL filters by lobby and active status'
    );
});
