<?php
declare(strict_types=1);

require_once __DIR__ . '/../../engine/autoload.php';
require_once __DIR__ . '/../Assert.php';
require_once __DIR__ . '/../FakeMysqli.php';

use PokerPinochle\Lib\Presence;

test('touch returns shaped online list and correct SQL', function () {
    $db = new FakeMysqli();
    $db->addRows([]);   // INSERT ... ON DUPLICATE KEY UPDATE (prepare + execute)
    $db->addRows([]);   // DELETE stale rows
    $db->addRows([      // SELECT everyone online
        ['user_uuid' => 'uuid-1', 'display_name' => 'Alice'],
        ['user_uuid' => 'uuid-2', 'display_name' => 'Bob'],
    ]);

    $online = (new Presence($db))->touch('uuid-1', 'Alice');

    Assert::equal(2, count($online), 'Presence/touch: one entry per scripted row');
    $shapeOk = true;
    foreach ($online as $u) {
        if (!is_array($u) || array_keys($u) !== ['uuid', 'name']) {
            $shapeOk = false;
        }
    }
    Assert::true($shapeOk, 'Presence/touch: every entry has exactly uuid and name keys');
    Assert::equal(
        [['uuid' => 'uuid-1', 'name' => 'Alice'], ['uuid' => 'uuid-2', 'name' => 'Bob']],
        $online,
        'Presence/touch: returns the scripted online users, in order'
    );

    $sql = $db->getSql();
    Assert::equal(3, count($sql), 'Presence/touch: three statements issued');
    Assert::true(strpos($sql[0] ?? '', 'INSERT') !== false, 'Presence/touch: 1st statement is an INSERT');
    Assert::true(strpos($sql[0] ?? '', 'ON DUPLICATE KEY UPDATE') !== false, 'Presence/touch: INSERT upserts (ON DUPLICATE KEY UPDATE)');
    Assert::true(strpos($sql[1] ?? '', 'DELETE') !== false, 'Presence/touch: 2nd statement is a DELETE');
    Assert::true(strpos($sql[2] ?? '', 'SELECT') !== false, 'Presence/touch: 3rd statement is a SELECT');
    Assert::equal([['uuid-1', 'Alice']], $db->getParams(), 'Presence/touch: INSERT bound uuid and name');
});

test('nobody online returns empty array', function () {
    $db = new FakeMysqli();
    $db->addRows([]);
    $db->addRows([]);
    $db->addRows([]);
    Assert::equal([], (new Presence($db))->touch('u', 'N'), 'Presence/touch: nobody online -> empty array');
});
