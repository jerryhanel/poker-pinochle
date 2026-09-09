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
// default — engine harness
// ===========================================================================
$argv = ['harness'];
if (isset($_GET['quiet'])) { $argv[] = '--quiet'; }
if (isset($_GET['hand']))  { $argv[] = '--hand'; }
$argc = count($argv);

require __DIR__ . '/engine/harness.php';
