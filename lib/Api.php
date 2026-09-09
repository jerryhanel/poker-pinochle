<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pusher.php';
require_once __DIR__ . '/GameStore.php';

// -----------------------------------------------------------------------------
// Api — the application logic behind the HTTP endpoints. Each method takes the
// caller's identity + a plain args array, does its own turn/phase/permission
// checks (mostly delegated to the engine), and returns an array or throws:
//   DomainException  -> 409 (illegal move / not your turn / wrong phase)
//   InvalidArgumentException -> 400 (bad input)
//
// The api/*.php files are thin request<->JSON shims over these.
// -----------------------------------------------------------------------------

final class Api
{
    // ---- helpers ---------------------------------------------------------

    private static function gameUuid(array $a): string
    {
        $u = $a['game'] ?? $a['g'] ?? '';
        if (!is_string($u) || !preg_match('/^[0-9a-f-]{36}$/i', $u)) {
            throw new InvalidArgumentException('Missing or bad game id.');
        }
        return $u;
    }

    private static function seatOf(State $g, string $uuid): int
    {
        foreach ($g->roster as $seat => $p) {
            if ($p['uuid'] === $uuid) {
                return (int) $seat;
            }
        }
        throw new DomainException('You are not seated in this game.');
    }

    /** Broadcast the public snapshot (all hands hidden) after a committed change. */
    private static function announce(string $gameUuid): void
    {
        try {
            $g = GameStore::load(pp_db(), $gameUuid);
            pp_broadcast('presence-pp-game-' . $gameUuid, 'state', GameStore::snapshotFor($g, null));
        } catch (Throwable $e) {
            // never let a broadcast failure break the request
        }
    }

    // ---- identity ------------------------------------------------------------

    public static function me(?array $user): array
    {
        if ($user === null) {
            throw new DomainException('Not signed in.');
        }
        return ['uuid' => $user['uuid'], 'name' => $user['name'], 'csrf' => pp_csrf_token()];
    }

    // ---- lobby -------------------------------------------------------------

    /** Player count decides the mode: even and >= 4 -> two teams, else cutthroat. */
    public static function modeFor(int $players): string
    {
        return ($players >= 4 && $players % 2 === 0) ? 'teams' : 'cutthroat';
    }

    public static function lobbyCreate(array $user, array $a): array
    {
        $players = (int) ($a['players'] ?? 0);
        $rules   = is_array($a['rules'] ?? null) ? $a['rules'] : [];

        if ($players < 2 || $players > 8) {
            throw new InvalidArgumentException('players must be 2–8.');
        }
        $mode = self::modeFor($players);   // derived; any client-sent mode is ignored

        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        $created = pp_tx(function (mysqli $db) use ($user, $players, $mode, $rules, $code) {
            $g = GameStore::createGame($db, $user['uuid'], $players, $mode, $rules, $code);
            GameStore::seatPlayer($db, $g['id'], 0, 0, $user['uuid'], $user['name'], true);
            return $g;
        });

        return [
            'gameUuid' => $created['uuid'],
            'joinCode' => $code,
            'seat'     => 0,
        ];
    }

    public static function lobbyList(): array
    {
        $db = pp_db();
        $rows = $db->query(
            "SELECT g.game_uuid, g.mode, g.player_count, g.join_code, g.created_at,
                    (SELECT COUNT(*) FROM game_player gp WHERE gp.game_id = g.id) AS seated
             FROM game g
             WHERE g.status = 'lobby'
             ORDER BY g.created_at DESC
             LIMIT 50"
        )->fetch_all(MYSQLI_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'gameUuid'    => $r['game_uuid'],
                'mode'        => $r['mode'],
                'playerCount' => (int) $r['player_count'],
                'seated'      => (int) $r['seated'],
                'joinCode'    => $r['join_code'],
                'createdAt'   => $r['created_at'],
            ];
        }
        return ['games' => $out];
    }

    public static function lobbyJoin(array $user, array $a): array
    {
        $byCode = isset($a['code']) && $a['code'] !== '';
        $ident  = $byCode ? strtoupper((string) $a['code']) : self::gameUuid($a);

        return pp_tx(function (mysqli $db) use ($user, $byCode, $ident) {
            $sql = $byCode
                ? "SELECT * FROM game WHERE join_code = ?"
                : "SELECT * FROM game WHERE game_uuid = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('s', $ident);
            $stmt->execute();
            $game = $stmt->get_result()->fetch_assoc();
            if (!$game) {
                throw new DomainException('No such game.');
            }
            $gameId  = (int) $game['id'];
            $players = (int) $game['player_count'];

            // already seated? idempotent.
            $mineStmt = $db->prepare("SELECT seat_no, side_no FROM game_player WHERE game_id = ? AND user_uuid = ?");
            $mineStmt->bind_param('is', $gameId, $user['uuid']);
            $mineStmt->execute();
            $mine = $mineStmt->get_result()->fetch_assoc();
            if ($mine) {
                return ['gameUuid' => $game['game_uuid'], 'seat' => (int) $mine['seat_no'], 'sideNo' => (int) $mine['side_no']];
            }

            if ($game['status'] !== 'lobby') {
                throw new DomainException('That game has already started.');
            }
            $seated = $db->query("SELECT seat_no FROM game_player WHERE game_id = {$gameId}")->fetch_all(MYSQLI_ASSOC);
            $taken  = array_map('intval', array_column($seated, 'seat_no'));
            $seat = null;
            for ($k = 0; $k < $players; $k++) {
                if (!in_array($k, $taken, true)) { $seat = $k; break; }
            }
            if ($seat === null) {
                throw new DomainException('That game is full.');
            }
            $sideNo = $game['mode'] === 'teams' ? $seat % 2 : $seat;
            GameStore::seatPlayer($db, $gameId, $seat, $sideNo, $user['uuid'], $user['name']);

            return ['gameUuid' => $game['game_uuid'], 'seat' => $seat, 'sideNo' => $sideNo];
        });
    }

    public static function lobbyLeave(array $user, array $a): array
    {
        $uuid = self::gameUuid($a);
        pp_tx(function (mysqli $db) use ($user, $uuid) {
            $game = $db->query("SELECT id, status FROM game WHERE game_uuid = '" . $db->real_escape_string($uuid) . "'")->fetch_assoc();
            if (!$game) {
                throw new DomainException('No such game.');
            }
            $gameId = (int) $game['id'];
            if ($game['status'] === 'lobby') {
                $stmt = $db->prepare("DELETE FROM game_player WHERE game_id = ? AND user_uuid = ?");
                $stmt->bind_param('is', $gameId, $user['uuid']);
                $stmt->execute();
                // host left an empty lobby -> drop the game
                $left = $db->query("SELECT COUNT(*) c FROM game_player WHERE game_id = {$gameId}")->fetch_assoc()['c'];
                if ((int) $left === 0) {
                    $db->query("DELETE FROM game WHERE id = {$gameId}");
                }
            } else {
                $stmt = $db->prepare("UPDATE game_player SET connection = 'left', left_at = NOW() WHERE game_id = ? AND user_uuid = ?");
                $stmt->bind_param('is', $gameId, $user['uuid']);
                $stmt->execute();
            }
        });
        self::announce($uuid);
        return ['left' => true];
    }

    // ---- game state ------------------------------------------------------

    public static function game(array $user, array $a): array
    {
        $uuid = self::gameUuid($a);
        $g = GameStore::load(pp_db(), $uuid);
        return GameStore::snapshotFor($g, $user['uuid']);
    }

    /**
     * Deal a hand. Host only. Works for the first hand (status 'lobby', all
     * seats filled) and every hand after (status 'active', current hand
     * 'scored', game not over).
     */
    public static function start(array $user, array $a): array
    {
        $uuid = self::gameUuid($a);
        $snap = pp_tx(function (mysqli $db) use ($user, $uuid) {
            $game = $db->query("SELECT * FROM game WHERE game_uuid = '" . $db->real_escape_string($uuid) . "'")->fetch_assoc();
            if (!$game) {
                throw new DomainException('No such game.');
            }
            if ($game['host_user_uuid'] !== $user['uuid']) {
                throw new DomainException('Only the host can deal.');
            }
            $gameId  = (int) $game['id'];
            $players = (int) $game['player_count'];

            if ($game['status'] === 'lobby') {
                $seated = (int) $db->query("SELECT COUNT(*) c FROM game_player WHERE game_id = {$gameId}")->fetch_assoc()['c'];
                if ($seated < $players) {
                    throw new DomainException("Need {$players} players — {$seated} seated.");
                }
            } elseif ($game['status'] === 'active') {
                $g = GameStore::load($db, $uuid);
                if ($g->isGameOver()) {
                    throw new DomainException('The game is over.');
                }
                if (!$g->hand || $g->hand['phase'] !== 'scored') {
                    throw new DomainException('Finish the current hand first.');
                }
                // every seated player must be ready
                $need = [];
                foreach ($g->roster as $seat => $p) {
                    if (($p['connection'] ?? 'connected') !== 'left') {
                        $need[] = (int) $seat;
                    }
                }
                $missing = array_diff($need, $g->readyList);
                if ($missing) {
                    throw new DomainException('Waiting for all players to ready up (' . count($missing) . ' not ready).');
                }
            } else {
                throw new DomainException('This game is complete.');
            }

            $g = GameStore::startHand($db, $uuid);   // random deck; rotates dealer + hand_no
            return GameStore::snapshotFor($g, $user['uuid']);
        });
        self::announce($uuid);
        return $snap;
    }

    /** Mark the caller ready for the next hand (only meaningful once scored). */
    public static function ready(array $user, array $a): array
    {
        $uuid = self::gameUuid($a);
        pp_tx(function (mysqli $db) use ($user, $uuid) {
            $g = GameStore::load($db, $uuid);
            if ($g->isGameOver()) {
                throw new DomainException('The game is over.');
            }
            if (!$g->hand || $g->hand['phase'] !== 'scored') {
                throw new DomainException('Nothing to ready up for yet.');
            }
            $seat = self::seatOf($g, $user['uuid']);

            $game = $db->query("SELECT id FROM game WHERE game_uuid = '" . $db->real_escape_string($uuid) . "'")->fetch_assoc();
            $hid  = (int) $db->query("SELECT id FROM hand WHERE game_id = " . (int) $game['id'] . " ORDER BY hand_no DESC LIMIT 1")->fetch_assoc()['id'];
            $cur  = $db->query("SELECT ready_json FROM hand WHERE id = {$hid}")->fetch_assoc()['ready_json'];
            $list = $cur ? json_decode($cur, true) : [];
            if (!is_array($list)) {
                $list = [];
            }
            if (!in_array($seat, $list, true)) {
                $list[] = $seat;
            }
            sort($list);
            $j = json_encode(array_values($list));
            $stmt = $db->prepare("UPDATE hand SET ready_json = ? WHERE id = ?");
            $stmt->bind_param('si', $j, $hid);
            $stmt->execute();
        });
        self::announce($uuid);
        $g = GameStore::load(pp_db(), $uuid);
        return GameStore::snapshotFor($g, $user['uuid']);
    }

    // ---- one endpoint per hand phase -----------------------------------

    public static function bid(array $user, array $a): array
    {
        $uuid = self::gameUuid($a);
        $action = (string) ($a['action'] ?? '');
        $amount = isset($a['amount']) && $a['amount'] !== '' ? (int) $a['amount'] : null;
        $snap = GameStore::withGame($uuid, $user['uuid'], function (State $g) use ($user, $action, $amount) {
            $g->bid(self::seatOf($g, $user['uuid']), $action, $amount);
        });
        self::announce($uuid);
        return $snap;
    }

    public static function declare(array $user, array $a): array
    {
        $uuid  = self::gameUuid($a);
        $trump = strtoupper((string) ($a['trump'] ?? ''));
        $snap = GameStore::withGame($uuid, $user['uuid'], function (State $g) use ($user, $trump) {
            $g->declareTrump(self::seatOf($g, $user['uuid']), $trump);
        });
        self::announce($uuid);
        return $snap;
    }

    public static function draw(array $user, array $a): array
    {
        $uuid     = self::gameUuid($a);
        $discards = array_values(array_filter((array) ($a['discards'] ?? []), 'is_string'));
        $snap = GameStore::withGame($uuid, $user['uuid'], function (State $g) use ($user, $discards) {
            $g->draw(self::seatOf($g, $user['uuid']), $discards);
        });
        self::announce($uuid);
        return $snap;
    }

    public static function play(array $user, array $a): array
    {
        $uuid = self::gameUuid($a);
        $card = strtoupper((string) ($a['card'] ?? ''));
        $snap = GameStore::withGame($uuid, $user['uuid'], function (State $g) use ($user, $card) {
            $g->play(self::seatOf($g, $user['uuid']), $card);
        });
        self::announce($uuid);
        return $snap;
    }

    // ---- chat -----------------------------------------------------------

    public static function chatPost(array $user, array $a): array
    {
        $text = trim((string) ($a['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 500) {
            throw new InvalidArgumentException('Message must be 1–500 characters.');
        }
        $gameUuid = isset($a['game']) && $a['game'] !== '' ? self::gameUuid($a) : null;

        $db = pp_db();
        $gameId = null;
        if ($gameUuid !== null) {
            $row = $db->query("SELECT id FROM game WHERE game_uuid = '" . $db->real_escape_string($gameUuid) . "'")->fetch_assoc();
            if (!$row) {
                throw new DomainException('No such game.');
            }
            $gameId = (int) $row['id'];
        }

        $stmt = $db->prepare(
            "INSERT INTO chat_message (game_id, user_uuid, display_name, body, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param('isss', $gameId, $user['uuid'], $user['name'], $text);
        $stmt->execute();
        $id = (int) $db->insert_id;

        $msg = [
            'id'   => $id,
            'uuid' => $user['uuid'],
            'name' => $user['name'],
            'body' => $text,
            'at'   => date('c'),
        ];
        pp_broadcast(
            $gameUuid !== null ? 'pp-chat-' . $gameUuid : 'pp-lobby',
            'chat',
            $msg
        );
        return ['message' => $msg];
    }

    public static function chatList(array $user, array $a): array
    {
        $gameUuid = isset($a['game']) && $a['game'] !== '' ? self::gameUuid($a) : null;
        $since    = (int) ($a['since'] ?? 0);

        $db = pp_db();
        if ($gameUuid !== null) {
            $row = $db->query("SELECT id FROM game WHERE game_uuid = '" . $db->real_escape_string($gameUuid) . "'")->fetch_assoc();
            $gameId = $row ? (int) $row['id'] : -1;
            $stmt = $db->prepare(
                "SELECT id, user_uuid, display_name, body, created_at
                 FROM chat_message WHERE game_id = ? AND id > ? ORDER BY id DESC LIMIT 50"
            );
            $stmt->bind_param('ii', $gameId, $since);
        } else {
            $stmt = $db->prepare(
                "SELECT id, user_uuid, display_name, body, created_at
                 FROM chat_message WHERE game_id IS NULL AND id > ? ORDER BY id DESC LIMIT 50"
            );
            $stmt->bind_param('i', $since);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $msgs = [];
        foreach (array_reverse($rows) as $r) {
            $msgs[] = [
                'id'   => (int) $r['id'],
                'uuid' => $r['user_uuid'],
                'name' => $r['display_name'],
                'body' => $r['body'],
                'at'   => $r['created_at'],
            ];
        }
        return ['messages' => $msgs];
    }
}
