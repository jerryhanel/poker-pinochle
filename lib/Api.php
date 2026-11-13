<?php
declare(strict_types=1);

namespace PokerPinochle\Lib;

use DomainException;
use InvalidArgumentException;
use Throwable;
use mysqli;
use PokerPinochle\Engine\State;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pusher.php';
require_once __DIR__ . '/GameStore.php';
require_once __DIR__ . '/Presence.php';
require_once __DIR__ . '/BotPlayer.php';

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

    /** Heartbeat for the "Users" menu — call on a timer while the app is open at all. */
    public static function presencePing(array $user): array
    {
        return ['users' => Presence::touch(pp_db(), $user['uuid'], $user['name'])];
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

    /** Open-seat lobbies AND in-progress games — the latter are watch-only, not joinable. */
    public static function lobbyList(): array
    {
        $db = pp_db();
        GameStore::pruneStale($db);
        $rows = $db->query(
            "SELECT g.game_uuid, g.mode, g.player_count, g.join_code, g.created_at, g.status,
                    (SELECT COUNT(*) FROM game_player gp WHERE gp.game_id = g.id) AS seated,
                    (SELECT COUNT(*) FROM game_viewer gv WHERE gv.game_id = g.id) AS viewers
             FROM game g
             WHERE g.status IN ('lobby','active')
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
                'status'      => $r['status'],
                'viewers'     => (int) $r['viewers'],
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

    /**
     * Spectate: no seat, no side, can't act — just watch the felt and chat.
     * Already seated? That's returned as-is; a player never gets demoted to a
     * viewer by clicking watch on their own game.
     */
    public static function lobbyWatch(array $user, array $a): array
    {
        $byCode = isset($a['code']) && $a['code'] !== '';
        $ident  = $byCode ? strtoupper((string) $a['code']) : self::gameUuid($a);

        $result = pp_tx(function (mysqli $db) use ($user, $byCode, $ident) {
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
            $gameId = (int) $game['id'];

            $mineStmt = $db->prepare("SELECT seat_no FROM game_player WHERE game_id = ? AND user_uuid = ?");
            $mineStmt->bind_param('is', $gameId, $user['uuid']);
            $mineStmt->execute();
            $mine = $mineStmt->get_result()->fetch_assoc();
            if ($mine) {
                return ['gameUuid' => $game['game_uuid'], 'seat' => (int) $mine['seat_no']];
            }

            GameStore::addViewer($db, $gameId, $user['uuid'], $user['name']);
            return ['gameUuid' => $game['game_uuid'], 'viewer' => true];
        });
        self::announce($result['gameUuid']);
        return $result;
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
            GameStore::removeViewer($db, $gameId, $user['uuid']);
            if ($game['status'] === 'lobby') {
                $stmt = $db->prepare("DELETE FROM game_player WHERE game_id = ? AND user_uuid = ?");
                $stmt->bind_param('is', $gameId, $user['uuid']);
                $stmt->execute();
                // host left an empty lobby -> drop the game
                $left = $db->query("SELECT COUNT(*) c FROM game_player WHERE game_id = {$gameId}")->fetch_assoc()['c'];
                if ((int) $left === 0) {
                    $db->query("DELETE FROM game WHERE id = {$gameId}");
                } else {
                    // table just dropped below full (or was already short) —
                    // any in-progress team-pairing vote is stale now
                    $db->query("UPDATE game SET team_vote_json = NULL WHERE id = {$gameId}");
                }
            } else {
                $stmt = $db->prepare("UPDATE game_player SET connection = 'left', left_at = NOW() WHERE game_id = ? AND user_uuid = ?");
                $stmt->bind_param('is', $gameId, $user['uuid']);
                $stmt->execute();

                // last one out turns off the lights — only for a still-active game;
                // a 'complete' game keeps its real outcome even after everyone leaves
                if ($game['status'] === 'active') {
                    $remaining = (int) $db->query(
                        "SELECT COUNT(*) c FROM game_player WHERE game_id = {$gameId} AND connection != 'left'"
                    )->fetch_assoc()['c'];
                    if ($remaining === 0) {
                        $db->query("UPDATE game SET status = 'abandoned', ended_at = NOW() WHERE id = {$gameId}");
                    }
                }
            }
        });
        self::announce($uuid);
        return ['left' => true];
    }

    /**
     * Remove one or all bots from the table. Any seated human may call this
     * unilaterally — no vote needed (unlike adding a bot, which commits
     * everyone; kicking one back off is low-stakes and trivially reversible
     * via a fresh bot vote).
     *
     * The seat is always fully vacated (DELETE, not just marked 'left') —
     * the engine's player count is fixed for a game's whole life, so a
     * "ghost" seat still gets dealt into and waited on forever if merely
     * flagged departed rather than actually freed. In the lobby that's the
     * whole story. Mid-game it means the currently in-progress hand can no
     * longer be completed as dealt, so it's voided as a misdeal instead —
     * no scoring applied (an unscored hand has no history to roll back),
     * deleted outright, and the table returns to "needs a full seat" before
     * Api::start() will deal again (typically via a fresh bot vote into the
     * freed seat).
     * { game, seat?: int, all?: bool } — pass `all: true` to clear every bot.
     */
    public static function removeBot(array $user, array $a): array
    {
        $uuid = self::gameUuid($a);
        $all  = !empty($a['all']);
        $seat = isset($a['seat']) ? (int) $a['seat'] : null;

        pp_tx(function (mysqli $db) use ($user, $uuid, $all, $seat) {
            $game = $db->query("SELECT * FROM game WHERE game_uuid = '" . $db->real_escape_string($uuid) . "'")->fetch_assoc();
            if (!$game) {
                throw new DomainException('No such game.');
            }
            if (!in_array($game['status'], ['lobby', 'active'], true)) {
                throw new DomainException('This game is complete.');
            }
            $gameId = (int) $game['id'];
            $g = GameStore::load($db, $uuid);
            self::seatOf($g, $user['uuid']);   // caller must be seated in this game

            $targets = [];
            if ($all) {
                foreach ($g->roster as $sn => $p) {
                    if (!empty($p['isBot'])) {
                        $targets[] = (int) $sn;
                    }
                }
            } else {
                if ($seat === null || $seat < 0 || $seat >= $g->players || empty($g->roster[$seat]['isBot'])) {
                    throw new DomainException('That seat is not a bot.');
                }
                $targets[] = $seat;
            }
            if (!$targets) {
                throw new DomainException('No bots to remove.');
            }

            foreach ($targets as $sn) {
                $stmt = $db->prepare("DELETE FROM game_player WHERE game_id = ? AND seat_no = ?");
                $stmt->bind_param('ii', $gameId, $sn);
                $stmt->execute();
            }

            if ($game['status'] === 'lobby') {
                $left = (int) $db->query("SELECT COUNT(*) c FROM game_player WHERE game_id = {$gameId}")->fetch_assoc()['c'];
                if ($left === 0) {
                    $db->query("DELETE FROM game WHERE id = {$gameId}");
                } else {
                    // table dropped below full — any in-progress team-pairing
                    // vote (or pending bot-seat votes for other seats) is stale
                    $db->query("UPDATE game SET team_vote_json = NULL, bot_votes_json = NULL WHERE id = {$gameId}");
                }
            } else {
                // misdeal — void whatever hand is currently in flight (never
                // scored, so no running-total rollback is needed)
                $hand = $db->query("SELECT id, phase FROM hand WHERE game_id = {$gameId} ORDER BY hand_no DESC LIMIT 1")->fetch_assoc();
                if ($hand && $hand['phase'] !== 'scored') {
                    $db->query("DELETE FROM hand WHERE id = " . (int) $hand['id']);
                }
            }
        });

        self::announce($uuid);
        $g = GameStore::load(pp_db(), $uuid);
        return GameStore::snapshotFor($g, $user['uuid']);
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
                if ($game['mode'] === 'teams') {
                    $tv = !empty($game['team_vote_json']) ? json_decode((string) $game['team_vote_json'], true) : null;
                    if (empty($tv['resolved'])) {
                        throw new DomainException('Everyone needs to lock in a team pick first.');
                    }
                }
            } elseif ($game['status'] === 'active') {
                $g = GameStore::load($db, $uuid);
                if ($g->isGameOver()) {
                    throw new DomainException('The game is over.');
                }
                if ($g->hand !== null && $g->hand['phase'] !== 'scored') {
                    throw new DomainException('Finish the current hand first.');
                }
                if ($g->hand === null) {
                    // a bot removal voided the in-progress hand as a misdeal —
                    // nothing to ready up for (no score was ever shown), just
                    // require the table to be full again before redealing
                    $seated = count($g->roster);
                    if ($seated < $g->players) {
                        throw new DomainException("Need {$g->players} players — {$seated} seated.");
                    }
                } else {
                    // normal post-score flow — every seated player must ready up
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

    /** Shared by ready() and botAct()'s post-hand ready-up branch. */
    private static function markReady(mysqli $db, int $gameId, int $seat): void
    {
        $hid  = (int) $db->query("SELECT id FROM hand WHERE game_id = {$gameId} ORDER BY hand_no DESC LIMIT 1")->fetch_assoc()['id'];
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
            self::markReady($db, (int) $game['id'], $seat);
        });
        self::announce($uuid);
        $g = GameStore::load(pp_db(), $uuid);
        return GameStore::snapshotFor($g, $user['uuid']);
    }

    /**
     * Submit (and optionally lock) the caller's team-pairing proposal for a
     * teams-mode lobby that's already full. Once every seated player has
     * locked, GameStore::teamVoteSubmit() resolves it automatically and
     * rewrites seats/sides — see that method for the algorithm.
     */
    public static function teamVote(array $user, array $a): array
    {
        $uuid  = self::gameUuid($a);
        $picks = array_values(array_filter((array) ($a['picks'] ?? []), 'is_string'));
        $lock  = !empty($a['lock']);

        $result = pp_tx(function (mysqli $db) use ($user, $uuid, $picks, $lock) {
            $game = $db->query("SELECT * FROM game WHERE game_uuid = '" . $db->real_escape_string($uuid) . "'")->fetch_assoc();
            if (!$game) {
                throw new DomainException('No such game.');
            }
            if ($game['mode'] !== 'teams') {
                throw new DomainException('Team voting only applies to teams-mode games.');
            }
            if ($game['status'] !== 'lobby') {
                throw new DomainException('Teams are already set for this game.');
            }
            $g = GameStore::load($db, $uuid);
            if (count($g->roster) < $g->players) {
                throw new DomainException('Waiting for the table to fill up first.');
            }
            if (!empty($g->teamVote['resolved'])) {
                throw new DomainException('Teams are already set for this game.');
            }
            return GameStore::teamVoteSubmit(
                $db, (int) $game['id'], $g->roster, $g->teamVote, $user['uuid'], $picks, $lock
            );
        });

        self::announce($uuid);
        $snap = GameStore::snapshotFor(GameStore::load(pp_db(), $uuid), $user['uuid']);
        $snap['teamVoteResolved'] = $result['resolved'];
        $snap['teamVoteMethod']   = $result['method'];
        return $snap;
    }

    // ---- bot seats ---------------------------------------------------------

    /**
     * Any seated human may propose/vote on seating a bot in an open lobby
     * seat or a seat abandoned mid-game (connection = 'left'). Unanimous yes
     * from every other currently-connected human seat seats the bot; any
     * single "no" cancels the proposal outright. See
     * GameStore::botVoteSubmit() for the full algorithm. { game, seat, vote? }
     * — vote omitted/true on the first call for a seat is an implicit
     * yes-propose.
     */
    public static function botVote(array $user, array $a): array
    {
        $uuid = self::gameUuid($a);
        $seat = (int) ($a['seat'] ?? -1);
        $vote = array_key_exists('vote', $a) ? (bool) $a['vote'] : true;

        $result = pp_tx(function (mysqli $db) use ($user, $uuid, $seat, $vote) {
            $game = $db->query("SELECT * FROM game WHERE game_uuid = '" . $db->real_escape_string($uuid) . "'")->fetch_assoc();
            if (!$game) {
                throw new DomainException('No such game.');
            }
            if (!in_array($game['status'], ['lobby', 'active'], true)) {
                throw new DomainException('This game is complete.');
            }
            $g = GameStore::load($db, $uuid);
            return GameStore::botVoteSubmit(
                $db, (int) $game['id'], $g->players, $g->mode, $g->roster,
                $g->botVotes, $seat, $user['uuid'], $vote
            );
        });

        self::announce($uuid);
        $snap = GameStore::snapshotFor(GameStore::load(pp_db(), $uuid), $user['uuid']);
        $snap['botVoteResolved']  = $result['resolved'];
        $snap['botVoteCancelled'] = $result['cancelled'];
        $snap['botName']          = $result['botName'];
        return $snap;
    }

    /**
     * Drive one bot's turn. Any seated, non-bot player may call this —
     * multiple browsers racing to trigger the same bot turn is harmless: only
     * the phase-specific "does this bot actually need to act right now"
     * check lets a mutation through, so a losing race just re-saves the same
     * state and returns the current snapshot.
     *
     * Two situations live outside the State/hand engine entirely — the
     * pre-deal team-pairing vote (game.team_vote_json) and the post-hand
     * ready-up (hand.ready_json) — so both are handled here, before the
     * hand-phase switch below, via their own direct GameStore/Api calls
     * rather than a State mutation through withGame(). A bot has no real
     * "preference" for either: it locks in a random valid teammate pick, and
     * readies up the instant a hand scores.
     */
    public static function botAct(array $user, array $a): array
    {
        $uuid = self::gameUuid($a);
        $seat = (int) ($a['seat'] ?? -1);

        $handledOffEngine = pp_tx(function (mysqli $db) use ($user, $uuid, $seat) {
            $g = GameStore::load($db, $uuid);
            self::seatOf($g, $user['uuid']);   // caller must be seated in this game
            if ($seat < 0 || $seat >= $g->players || empty($g->roster[$seat]['isBot'])) {
                throw new DomainException("Seat {$seat} is not a bot.");
            }
            $botUuid = $g->roster[$seat]['uuid'];

            if (!$g->hand && $g->mode === 'teams' && count($g->roster) >= $g->players
                && empty($g->teamVote['resolved']) && empty($g->teamVote['ballots'][$botUuid]['locked'])
            ) {
                $others = [];
                foreach ($g->roster as $sn => $p) {
                    if ($sn !== $seat) {
                        $others[] = $p['uuid'];
                    }
                }
                shuffle($others);
                $need  = intdiv($g->players, 2) - 1;
                $picks = array_slice($others, 0, $need);

                $game = $db->query("SELECT id FROM game WHERE game_uuid = '" . $db->real_escape_string($uuid) . "'")->fetch_assoc();
                GameStore::teamVoteSubmit($db, (int) $game['id'], $g->roster, $g->teamVote, $botUuid, $picks, true);
                return true;
            }

            if ($g->hand && $g->hand['phase'] === 'scored' && !$g->isGameOver() && !in_array($seat, $g->readyList, true)) {
                $game = $db->query("SELECT id FROM game WHERE game_uuid = '" . $db->real_escape_string($uuid) . "'")->fetch_assoc();
                self::markReady($db, (int) $game['id'], $seat);
                return true;
            }

            return false;
        });

        if ($handledOffEngine) {
            self::announce($uuid);
            return GameStore::snapshotFor(GameStore::load(pp_db(), $uuid), $user['uuid']);
        }

        $snap = GameStore::withGame($uuid, $user['uuid'], function (State $g) use ($user, $seat) {
            self::seatOf($g, $user['uuid']);   // caller must be seated in this game

            if ($seat < 0 || $seat >= $g->players || empty($g->roster[$seat]['isBot'])) {
                throw new DomainException("Seat {$seat} is not a bot.");
            }
            if (!$g->hand) {
                return;
            }

            switch ($g->hand['phase']) {
                case 'auction':
                    if ($g->currentTurnSeat() !== $seat) {
                        return;
                    }
                    $decision = BotPlayer::decideBid($g, $seat);
                    $g->bid($seat, $decision['action'], $decision['amount']);
                    break;

                case 'declare':
                    if ($g->hand['bidWinnerSeat'] !== $seat) {
                        return;
                    }
                    $g->declareInSuit($seat, BotPlayer::decideDeclareSuit($g, $seat));
                    break;

                case 'draw':
                    if (!in_array($seat, $g->pendingDiscardSeats(), true)) {
                        return;
                    }
                    $g->draw($seat, BotPlayer::decideDraw($g, $seat));
                    break;

                case 'play':
                    if ($g->playTurnSeat() !== $seat) {
                        return;
                    }
                    $g->play($seat, BotPlayer::decidePlay($g, $seat));
                    break;

                default:
                    return;   // meld/scored — nothing for a bot to do
            }
        });

        self::announce($uuid);
        return $snap;
    }

    /** Host sets or clears the table's meeting link (e.g. a Google Meet URL). Host only. */
    public static function setMeetUrl(array $user, array $a): array
    {
        $uuid = self::gameUuid($a);
        $url  = trim((string) ($a['url'] ?? ''));
        if ($url !== '' && !preg_match('~^https?://~i', $url)) {
            throw new DomainException('Meeting link must start with http:// or https://');
        }
        if (strlen($url) > 500) {
            throw new DomainException('That link is too long.');
        }

        pp_tx(function (mysqli $db) use ($user, $uuid, $url) {
            $game = $db->query("SELECT * FROM game WHERE game_uuid = '" . $db->real_escape_string($uuid) . "'")->fetch_assoc();
            if (!$game) {
                throw new DomainException('No such game.');
            }
            if ($game['host_user_uuid'] !== $user['uuid']) {
                throw new DomainException('Only the host can set the meeting link.');
            }
            GameStore::setMeetUrl($db, (int) $game['id'], $url === '' ? null : $url);
        });

        self::announce($uuid);
        return GameStore::snapshotFor(GameStore::load(pp_db(), $uuid), $user['uuid']);
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
        $uuid   = self::gameUuid($a);
        $inSuit = strtoupper((string) ($a['inSuit'] ?? ''));
        $snap = GameStore::withGame($uuid, $user['uuid'], function (State $g) use ($user, $inSuit) {
            $g->declareInSuit(self::seatOf($g, $user['uuid']), $inSuit);
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
