<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// dev.php — PIN-gated browser tooling. Plain text output.
//
//   /poker-pinochle/dev.php            engine harness: tests + sample hand
//   /poker-pinochle/dev.php?quiet=1    engine harness: tests only
//   /poker-pinochle/dev.php?hand=1     engine harness: sample hand only
//   /poker-pinochle/dev.php?db=1       persistence round-trip against the live DB
// -----------------------------------------------------------------------------

require __DIR__ . '/lib/bootstrap.php';

if (pp_current_user() === null) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Sign in at /poker-pinochle/ first (this page is PIN-gated dev tooling).\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "Poker Pinochle — dev tooling\nPHP " . PHP_VERSION . "\n";

// ===========================================================================
// ?db=1 — GameStore persistence round-trip
// ===========================================================================
if (isset($_GET['db'])) {
    require __DIR__ . '/lib/GameStore.php';

    $PASS = 0; $FAIL = 0;
    $ok = function (bool $c, string $m) use (&$PASS, &$FAIL) {
        if ($c) { $PASS++; } else { $FAIL++; echo "  FAIL  {$m}\n"; }
    };

    echo "\n== DB persistence round-trip ==\n";
    try {
        $db = pp_db();
        echo "connected: {$db->host_info}\n";
    } catch (Throwable $e) {
        echo "CANNOT CONNECT: {$e->getMessage()}\n";
        exit(1);
    }

    $col = $db->query("SHOW COLUMNS FROM hand LIKE 'state_json'")->fetch_assoc();
    if (!$col) {
        echo "!! hand.state_json is MISSING — run migrations/0002_hand_state.sql as root first.\n";
        exit(1);
    }
    echo "hand.state_json present\n";

    $me = pp_current_user();
    $gameId = null;
    try {
        // create + seat 4 players (you + 3 bots)
        $created = pp_tx(function (mysqli $db) use ($me) {
            $g = GameStore::createGame($db, $me['uuid'], 4, 'teams');
            $names = [$me['name'], 'Bot-B', 'Bot-C', 'Bot-D'];
            for ($seat = 0; $seat < 4; $seat++) {
                $uuid = $seat === 0 ? $me['uuid'] : pp_uuid_for_name($names[$seat]);
                GameStore::seatPlayer($db, $g['id'], $seat, $seat % 2, $uuid, $names[$seat], $seat === 0);
            }
            return $g;
        });
        $gameId = (int) $created['id'];
        $uuid   = $created['uuid'];
        echo "created game {$uuid} (id {$gameId}), 4 seats\n";

        // deal a deterministic hand
        pp_tx(function (mysqli $db) use ($uuid) {
            GameStore::startHand($db, $uuid, Deck::shuffle(Deck::fresh(), 999));
        });
        echo "hand dealt\n";

        // one engine action per load/mutate/save cycle — exactly the API pattern
        $step = function (callable $mut) use ($uuid) {
            return pp_tx(function (mysqli $db) use ($uuid, $mut) {
                $g = GameStore::load($db, $uuid);
                $mut($g);
                GameStore::save($db, $uuid, $g);
                return $g;
            });
        };

        do { $g = $step(function (State $g) { if ($g->hand['phase'] === 'auction') $g->bid($g->auctionTurnSeat(), 'pass'); }); }
        while ($g->hand['phase'] === 'auction');
        echo "auction done -> phase {$g->hand['phase']}, bid winner seat {$g->hand['bidWinnerSeat']} @ {$g->hand['winningBid']}\n";

        $step(function (State $g) { $g->declareTrump($g->hand['bidWinnerSeat'], 'S'); });

        do { $g = $step(function (State $g) { if ($g->hand['phase'] === 'draw') $g->draw($g->drawTurnSeat(), []); }); }
        while ($g->hand['phase'] === 'draw');
        echo "draw done -> phase {$g->hand['phase']}\n";

        $plays = 0;
        do {
            $g = $step(function (State $g) {
                if ($g->hand['phase'] === 'play') {
                    $s = $g->playTurnSeat();
                    $g->play($s, $g->legalPlaysFor($s)[0]);
                }
            });
            $plays++;
        } while ($g->hand['phase'] === 'play' && $plays < 40);
        echo "play done in {$plays} steps -> phase {$g->hand['phase']}\n";

        // reload from scratch and verify it matches
        $fresh = pp_tx(function (mysqli $db) use ($uuid) { return GameStore::load($db, $uuid); });
        $ok($fresh->hand['phase'] === 'scored', 'reloaded hand is scored');
        $res = $fresh->handResult();
        $ok($res !== null, 'reloaded result present');
        $ok($res == $g->handResult(), 'reloaded result matches the in-memory result');
        echo "  hand scores " . json_encode($res['handScores']) .
             "  running " . json_encode($res['runningAfter']) . "\n";
        $ok($fresh->running == $g->running, 'reloaded running totals match');

        // history rows
        $hid = (int) $db->query("SELECT id FROM hand WHERE game_id = {$gameId} ORDER BY hand_no DESC LIMIT 1")
                        ->fetch_assoc()['id'];
        $counts = [];
        foreach (['bid', 'trick', 'trick_play', 'hand_player', 'hand_side'] as $t) {
            $counts[$t] = (int) $db->query("SELECT COUNT(*) c FROM {$t} WHERE hand_id = {$hid}")->fetch_assoc()['c'];
        }
        echo "  history rows: " . json_encode($counts) . "\n";
        $ok($counts['trick'] === 5, '5 trick rows');
        $ok($counts['trick_play'] === 20, '20 trick_play rows');
        $ok($counts['hand_player'] === 4, '4 hand_player rows');
        $ok($counts['hand_side'] === 2, '2 hand_side rows');
        $ok($counts['bid'] >= 4, 'bid log rows written');

        $vps = $db->query("SELECT * FROM v_player_stats WHERE user_uuid = '" . $db->real_escape_string($me['uuid']) . "'")->fetch_assoc();
        echo "  v_player_stats(you): " . json_encode($vps) . "\n";
    } catch (Throwable $e) {
        echo "DB TEST ERROR: {$e->getMessage()}\n";
        echo $e->getTraceAsString() . "\n";
        $FAIL++;
    } finally {
        if ($gameId !== null) {
            $db->query("DELETE FROM game WHERE id = {$gameId}");   // cascades to all child rows
            echo "cleaned up game {$gameId}\n";
        }
    }

    echo "\n-------------------------------------------\n";
    echo ($FAIL === 0 ? "DB ROUND-TRIP OK ({$PASS} checks)" : "{$PASS} passed, {$FAIL} FAILED") . "\n";
    exit($FAIL === 0 ? 0 : 1);
}

// ===========================================================================
// ?soketi=1 — fire a real broadcast through the trigger and report
// ===========================================================================
if (isset($_GET['soketi'])) {
    require __DIR__ . '/lib/pusher.php';
    $cfg = pp_config()['soketi'] ?? [];
    echo "\n== Soketi trigger ==\n";
    echo "target: " . ($cfg['scheme'] ?? '?') . "://" . ($cfg['host'] ?? '?') . ":" . ($cfg['port'] ?? '?')
        . "  app=" . ($cfg['app_id'] ?? '?') . "\n";

    $me = pp_current_user();
    $r  = pp_broadcast('pp-lobby', 'dev-ping', [
        'from' => $me['name'],
        'at'   => date('c'),
        'note' => 'poker-pinochle dev.php?soketi=1',
    ]);
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    echo ($r['ok'] ? "SOKETI TRIGGER OK (published to pp-lobby)\n" : "SOKETI TRIGGER FAILED\n");
    exit($r['ok'] ? 0 : 1);
}

// ===========================================================================
// ?api=1 — drive the Api layer end to end (no HTTP; direct calls)
// ===========================================================================
if (isset($_GET['api'])) {
    require __DIR__ . '/lib/Api.php';

    $PASS = 0; $FAIL = 0;
    $ok = function (bool $c, string $m) use (&$PASS, &$FAIL) {
        if ($c) { $PASS++; } else { $FAIL++; echo "  FAIL  {$m}\n"; }
    };

    echo "\n== API end-to-end ==\n";
    $me   = pp_current_user();
    $bots = [
        ['uuid' => pp_uuid_for_name('Bot-B'), 'name' => 'Bot-B'],
        ['uuid' => pp_uuid_for_name('Bot-C'), 'name' => 'Bot-C'],
        ['uuid' => pp_uuid_for_name('Bot-D'), 'name' => 'Bot-D'],
    ];
    $users  = array_merge([$me], $bots);   // index = seat
    $gameId = null;

    try {
        $c = Api::lobbyCreate($me, ['players' => 4, 'mode' => 'teams']);
        $uuid = $c['gameUuid'];
        $ok($c['seat'] === 0, 'creator seated at 0');
        echo "created {$uuid}  code {$c['joinCode']}\n";

        foreach ($bots as $i => $b) {
            $j = Api::lobbyJoin($b, ['game' => $uuid]);
            $ok($j['seat'] === $i + 1, "bot " . ($i + 1) . " took seat " . ($i + 1));
        }
        $ok(Api::lobbyJoin($bots[0], ['game' => $uuid])['seat'] === 1, 're-join is idempotent');

        $list = Api::lobbyList()['games'];
        $mine = null;
        foreach ($list as $row) { if ($row['gameUuid'] === $uuid) { $mine = $row; } }
        $ok($mine && $mine['seated'] === 4, 'lobby list shows 4/4 seated');

        try { Api::start($bots[0], ['game' => $uuid]); $ok(false, 'non-host start rejected'); }
        catch (DomainException $e) { $ok(true, 'non-host start rejected'); }

        $snap = Api::start($me, ['game' => $uuid]);
        $ok($snap['phase'] === 'auction', 'start -> auction');

        $db = pp_db();
        $gameId = (int) $db->query("SELECT id FROM game WHERE game_uuid = '" . $db->real_escape_string($uuid) . "'")->fetch_assoc()['id'];

        $steps = 0;
        while ($steps++ < 60) {
            $s  = Api::game($me, ['game' => $uuid]);
            $ph = $s['phase'];
            if ($ph === 'scored') { break; }
            $ts = $s['turnSeat'];
            $u  = $users[$ts];
            if ($ph === 'auction') {
                Api::bid($u, ['game' => $uuid, 'action' => 'pass']);
            } elseif ($ph === 'declare') {
                Api::declare($u, ['game' => $uuid, 'trump' => 'S']);
            } elseif ($ph === 'draw') {
                Api::draw($u, ['game' => $uuid, 'discards' => []]);
            } elseif ($ph === 'play') {
                $mv = Api::game($u, ['game' => $uuid]);
                Api::play($u, ['game' => $uuid, 'card' => $mv['legalPlays'][0]]);
            } else {
                $ok(false, "unexpected phase {$ph}");
                break;
            }
        }
        $final = Api::game($me, ['game' => $uuid]);
        $ok($final['phase'] === 'scored', 'hand played through to scored via the API');
        $ok(isset($final['result']['handScores']), 'result present in the snapshot');
        echo "  scores " . json_encode($final['result']['handScores']) .
             "  running " . json_encode($final['result']['runningAfter']) . "\n";

        // next hand is gated on every seated player readying up
        try {
            Api::start($me, ['game' => $uuid]);
            $ok(false, 'deal blocked before everyone is ready');
        } catch (DomainException $e) {
            $ok(true, 'deal blocked before everyone is ready');
        }
        foreach ($users as $u) { Api::ready($u, ['game' => $uuid]); }
        $rs = Api::game($me, ['game' => $uuid]);
        $ok(count($rs['ready']) === 4, 'all four readied up');
        $ok(is_array($rs['capturedBySide']) && count($rs['capturedBySide'][0]) + count($rs['capturedBySide'][1]) === 20,
            'capturedBySide holds all 20 played cards');
        try {
            Api::start($bots[0], ['game' => $uuid]);
            $ok(false, 'non-host deal rejected even when all ready');
        } catch (DomainException $e) {
            $ok(true, 'non-host deal rejected even when all ready');
        }
        $h2 = Api::start($me, ['game' => $uuid]);
        $ok($h2['phase'] === 'auction' && $h2['handNo'] === 2, 'host deals hand 2 once all ready');

        // chat
        Api::chatPost($me, ['game' => $uuid, 'text' => 'good game']);
        Api::chatPost($bots[0], ['game' => $uuid, 'text' => 'gg']);
        $msgs = Api::chatList($me, ['game' => $uuid])['messages'];
        $ok(count($msgs) === 2 && $msgs[0]['body'] === 'good game', 'chat stored + listed in order');
    } catch (Throwable $e) {
        echo "API TEST ERROR: {$e->getMessage()}\n" . $e->getTraceAsString() . "\n";
        $FAIL++;
    } finally {
        if ($gameId !== null) {
            pp_db()->query("DELETE FROM game WHERE id = {$gameId}");
            echo "cleaned up game {$gameId}\n";
        }
    }

    // --- 2-player heads-up ---
    echo "\n-- 2-player heads-up --\n";
    $g2Id = null;
    try {
        $c2 = Api::lobbyCreate($me, ['players' => 2, 'mode' => 'teams']); // mode arg ignored
        $u2 = $c2['gameUuid'];
        $ok(Api::game($me, ['game' => $u2])['mode'] === 'cutthroat', '2 players -> cutthroat mode');
        Api::lobbyJoin($bots[0], ['game' => $u2]);
        $db = pp_db();
        $g2Id = (int) $db->query("SELECT id FROM game WHERE game_uuid = '" . $db->real_escape_string($u2) . "'")->fetch_assoc()['id'];
        Api::start($me, ['game' => $u2]);
        $two = [$me, $bots[0]];
        $steps = 0;
        while ($steps++ < 40) {
            $s = Api::game($me, ['game' => $u2]);
            if ($s['phase'] === 'scored') { break; }
            $u = $two[$s['turnSeat']];
            if ($s['phase'] === 'auction') { Api::bid($u, ['game' => $u2, 'action' => 'pass']); }
            elseif ($s['phase'] === 'declare') { Api::declare($u, ['game' => $u2, 'trump' => 'H']); }
            elseif ($s['phase'] === 'draw') { Api::draw($u, ['game' => $u2, 'discards' => []]); }
            elseif ($s['phase'] === 'play') {
                $mv = Api::game($u, ['game' => $u2]);
                Api::play($u, ['game' => $u2, 'card' => $mv['legalPlays'][0]]);
            }
        }
        $f2 = Api::game($me, ['game' => $u2]);
        $ok($f2['phase'] === 'scored', '2-player hand played through to scored');
        $ok($f2['target'] === 50, '2-player game target is 50');
        echo "  scores " . json_encode($f2['result']['handScores']) . "\n";
    } catch (Throwable $e) {
        echo "2P TEST ERROR: {$e->getMessage()}\n";
        $FAIL++;
    } finally {
        if ($g2Id !== null) { pp_db()->query("DELETE FROM game WHERE id = {$g2Id}"); echo "cleaned up game {$g2Id}\n"; }
    }

    echo "\n-------------------------------------------\n";
    echo ($FAIL === 0 ? "API OK ({$PASS} checks)" : "{$PASS} passed, {$FAIL} FAILED") . "\n";
    exit($FAIL === 0 ? 0 : 1);
}

// ===========================================================================
// default — engine harness
// ===========================================================================
$argv = ['harness'];
if (isset($_GET['quiet'])) { $argv[] = '--quiet'; }
if (isset($_GET['hand']))  { $argv[] = '--hand'; }
$argc = count($argv);

require __DIR__ . '/engine/harness.php';
