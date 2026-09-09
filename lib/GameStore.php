<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../engine/State.php';

// -----------------------------------------------------------------------------
// GameStore — maps the engine State <-> the poker_pinochle tables.
//
//   hand.state_json  is the authoritative in-flight hand state. Every request
//   loads it, mutates through validated State methods, and saves it back.
//
//   When a hand reaches 'scored', save() also flushes the normalized history
//   (bid / trick / trick_play / hand_player / hand_side) and rolls the running
//   scores into game_side.score / game.winning_side_no. Those rows feed
//   v_player_stats and are the durable record.
//
// All writes go through pp_tx() at the call site (see withGame()).
// -----------------------------------------------------------------------------

final class GameStore
{
    // ---- small query helpers -------------------------------------------------

    private static function exec(mysqli $db, string $sql, string $types = '', array $params = []): mysqli_stmt
    {
        $stmt = $db->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt;
    }

    private static function row(mysqli $db, string $sql, string $types = '', array $params = []): ?array
    {
        $res = self::exec($db, $sql, $types, $params)->get_result();
        $r = $res ? $res->fetch_assoc() : null;
        return $r ?: null;
    }

    private static function rows(mysqli $db, string $sql, string $types = '', array $params = []): array
    {
        $res = self::exec($db, $sql, $types, $params)->get_result();
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }

    private static function j($v): string
    {
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ---- lifecycle ---------------------------------------------------------

    /**
     * Create a lobby game. Returns ['id'=>int, 'uuid'=>string].
     * $rules is merged with the engine defaults and frozen into game.rules_json.
     */
    public static function createGame(
        mysqli $db,
        string $hostUuid,
        int $players,
        string $mode,
        array $rules = [],
        ?string $joinCode = null
    ): array {
        $g = new State($players, $mode, $rules);        // resolves defaults + target + sides

        $frozen = array_merge($g->rules, [
            'deck'      => 48,
            'hand_size' => 5,
            'bid_min'   => $players,
            'bid_max'   => Auction::BID_MAX,
            'target'    => $g->target,
        ]);

        $uuid = pp_uuid_v4();
        self::exec(
            $db,
            "INSERT INTO game
               (game_uuid, status, mode, player_count, target_score, host_user_uuid,
                is_private, join_code, rules_json, current_hand_no, created_at)
             VALUES (?, 'lobby', ?, ?, ?, ?, ?, ?, ?, 0, NOW())",
            'ssiisiss',
            [$uuid, $mode, $players, $g->target, $hostUuid,
             $joinCode !== null ? 1 : 0, $joinCode, self::j($frozen)]
        );
        $gameId = (int) $db->insert_id;

        foreach (array_keys($g->sides) as $sideNo) {
            self::exec(
                $db,
                "INSERT INTO game_side (game_id, side_no, label, score, is_winner)
                 VALUES (?, ?, NULL, 0, 0)",
                'ii',
                [$gameId, $sideNo]
            );
        }

        return ['id' => $gameId, 'uuid' => $uuid];
    }

    public static function seatPlayer(
        mysqli $db,
        int $gameId,
        int $seat,
        int $sideNo,
        string $uuid,
        string $name,
        bool $isHost = false
    ): void {
        self::exec(
            $db,
            "INSERT INTO game_player
               (game_id, seat_no, side_no, user_uuid, display_name, is_host, connection, joined_at)
             VALUES (?, ?, ?, ?, ?, ?, 'connected', NOW())
             ON DUPLICATE KEY UPDATE
               side_no = VALUES(side_no), user_uuid = VALUES(user_uuid),
               display_name = VALUES(display_name), is_host = VALUES(is_host),
               connection = 'connected', left_at = NULL",
            'iiissi',
            [$gameId, $seat, $sideNo, $uuid, $name, $isHost ? 1 : 0]
        );
    }

    /** @return array{id:int,row:array} */
    private static function gameByUuid(mysqli $db, string $gameUuid): array
    {
        $row = self::row($db, "SELECT * FROM game WHERE game_uuid = ?", 's', [$gameUuid]);
        if ($row === null) {
            throw new DomainException("No game {$gameUuid}.");
        }
        return ['id' => (int) $row['id'], 'row' => $row];
    }

    private static function roster(mysqli $db, int $gameId): array
    {
        $out = [];
        foreach (self::rows(
            $db,
            "SELECT seat_no, side_no, user_uuid, display_name, is_host, connection
             FROM game_player WHERE game_id = ? ORDER BY seat_no",
            'i',
            [$gameId]
        ) as $r) {
            $out[(int) $r['seat_no']] = [
                'uuid'       => $r['user_uuid'],
                'name'       => $r['display_name'],
                'sideNo'     => (int) $r['side_no'],
                'isHost'     => (bool) $r['is_host'],
                'connection' => $r['connection'],
            ];
        }
        return $out;
    }

    /** Reconstruct the full game (shell + active hand) from the DB. */
    public static function load(mysqli $db, string $gameUuid): State
    {
        ['id' => $gameId, 'row' => $game] = self::gameByUuid($db, $gameUuid);

        $players = (int) $game['player_count'];
        $mode    = $game['mode'];
        $rules   = json_decode((string) $game['rules_json'], true) ?: [];

        $running = [];
        $winner  = $game['winning_side_no'] !== null ? (int) $game['winning_side_no'] : null;
        foreach (self::rows($db, "SELECT side_no, score, is_winner FROM game_side WHERE game_id = ? ORDER BY side_no", 'i', [$gameId]) as $s) {
            $running[(int) $s['side_no']] = (int) $s['score'];
            if ((int) $s['is_winner'] === 1) {
                $winner = (int) $s['side_no'];
            }
        }

        $handRow = self::row($db, "SELECT * FROM hand WHERE game_id = ? ORDER BY hand_no DESC LIMIT 1", 'i', [$gameId]);
        $dealerSeat = $handRow ? (int) $handRow['dealer_seat'] : null;
        $handNo     = (int) $game['current_hand_no'];

        $g = State::rebuild($players, $mode, $rules, $running, $handNo, $dealerSeat, $winner);
        if ($handRow && $handRow['state_json'] !== null && $handRow['state_json'] !== '') {
            $g->importHand(json_decode((string) $handRow['state_json'], true));
        }
        $g->roster   = self::roster($db, $gameId);
        $g->journal  = self::journal($db, $gameId);
        $g->joinCode = $game['join_code'] ?? null;
        if ($handRow && !empty($handRow['ready_json'])) {
            $rl = json_decode((string) $handRow['ready_json'], true);
            $g->readyList = is_array($rl) ? array_map('intval', $rl) : [];
        }

        return $g;
    }

    /** Scored-hand history (one entry per hand) for the score pad. */
    private static function journal(mysqli $db, int $gameId): array
    {
        $rows = self::rows(
            $db,
            "SELECT h.hand_no, h.bid_winner_seat, h.bid_winner_side_no, h.winning_bid, h.bid_made, h.trump,
                    hs.side_no, hs.meld_points, hs.counters_captured, hs.hand_score, hs.score_after
             FROM hand h
             JOIN hand_side hs ON hs.hand_id = h.id
             WHERE h.game_id = ? AND h.phase = 'scored'
             ORDER BY h.hand_no, hs.side_no",
            'i',
            [$gameId]
        );
        $out = [];
        foreach ($rows as $r) {
            $hn = (int) $r['hand_no'];
            if (!isset($out[$hn])) {
                $out[$hn] = [
                    'handNo'     => $hn,
                    'bidderSeat' => $r['bid_winner_seat'] !== null ? (int) $r['bid_winner_seat'] : null,
                    'bidderSide' => $r['bid_winner_side_no'] !== null ? (int) $r['bid_winner_side_no'] : null,
                    'bid'        => (int) $r['winning_bid'],
                    'trump'      => $r['trump'],
                    'made'       => (int) $r['bid_made'] === 1,
                    'sides'      => [],
                ];
            }
            $out[$hn]['sides'][(int) $r['side_no']] = [
                'meld'     => (int) $r['meld_points'],
                'counters' => (int) $r['counters_captured'],
                'delta'    => (int) $r['hand_score'],
                'after'    => (int) $r['score_after'],
            ];
        }
        return array_values($out);
    }

    /** Deal and persist a fresh hand. Pass $deck only for deterministic tests. */
    public static function startHand(mysqli $db, string $gameUuid, ?array $deck = null): State
    {
        ['id' => $gameId] = self::gameByUuid($db, $gameUuid);
        $g = self::load($db, $gameUuid);

        $g->startHand($deck ?? Deck::shuffle(Deck::fresh()));
        $h = $g->hand;

        self::exec(
            $db,
            "INSERT INTO hand (game_id, hand_no, dealer_seat, phase, trump, deck_json, state_json, started_at)
             VALUES (?, ?, ?, 'auction', NULL, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               dealer_seat = VALUES(dealer_seat), phase = 'auction', trump = NULL,
               deck_json = VALUES(deck_json), state_json = VALUES(state_json),
               bid_winner_seat = NULL, bid_winner_side_no = NULL, winning_bid = NULL,
               bid_made = NULL, scored_at = NULL",
            'iiiss',
            [$gameId, $g->handNo, $h['dealerSeat'], self::j($h['deck']), self::j($h)]
        );

        self::exec(
            $db,
            "UPDATE game
               SET current_hand_no = ?, status = 'active',
                   started_at = COALESCE(started_at, NOW())
             WHERE id = ?",
            'ii',
            [$g->handNo, $gameId]
        );

        return $g;
    }

    /** Persist the current hand's state (+ history on 'scored'). */
    public static function save(mysqli $db, string $gameUuid, State $g): void
    {
        ['id' => $gameId] = self::gameByUuid($db, $gameUuid);
        $h = $g->hand;
        if ($h === null) {
            return;
        }

        $handRow = self::row($db, "SELECT id FROM hand WHERE game_id = ? AND hand_no = ?", 'ii', [$gameId, $g->handNo]);
        if ($handRow === null) {
            self::exec(
                $db,
                "INSERT INTO hand (game_id, hand_no, dealer_seat, phase, deck_json, state_json, started_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                'iiisss',
                [$gameId, $g->handNo, $h['dealerSeat'], $h['phase'], self::j($h['deck']), self::j($h)]
            );
            $handId = (int) $db->insert_id;
        } else {
            $handId = (int) $handRow['id'];
        }

        $scored  = $h['phase'] === 'scored';
        $bidMade = $scored ? ($h['result']['made'] ? 1 : 0) : null;

        self::exec(
            $db,
            "UPDATE hand SET
               phase = ?, trump = ?, bid_winner_seat = ?, bid_winner_side_no = ?,
               winning_bid = ?, bid_made = ?, state_json = ?,
               scored_at = IF(? = 'scored', COALESCE(scored_at, NOW()), scored_at)
             WHERE id = ?",
            'ssiiiissi',
            [
                $h['phase'], $h['trump'], $h['bidWinnerSeat'], $h['bidWinnerSideNo'],
                $h['winningBid'], $bidMade, self::j($h), $h['phase'], $handId,
            ]
        );

        if ($scored) {
            self::flushHistory($db, $gameId, $handId, $g);
        }
    }

    private static function flushHistory(mysqli $db, int $gameId, int $handId, State $g): void
    {
        $h      = $g->hand;
        $roster = $g->roster ?: self::roster($db, $gameId);
        $uuidOf = static function (int $seat) use ($roster): string {
            return $roster[$seat]['uuid'] ?? '';
        };

        foreach (['bid', 'trick_play', 'trick', 'hand_player', 'hand_side'] as $t) {
            self::exec($db, "DELETE FROM {$t} WHERE hand_id = ?", 'i', [$handId]);
        }

        // --- bid log ---
        $seq = 0;
        foreach ($h['auction']['log'] as $entry) {
            $seq++;
            self::exec(
                $db,
                "INSERT INTO bid (hand_id, seq, seat_no, user_uuid, action, amount, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                'iiissi',
                [$handId, $seq, $entry['seat'], $uuidOf($entry['seat']), $entry['action'], $entry['amount']]
            );
        }
        if (!empty($h['stuck'])) {
            $seq++;
            self::exec(
                $db,
                "INSERT INTO bid (hand_id, seq, seat_no, user_uuid, action, amount, created_at)
                 VALUES (?, ?, ?, ?, 'stuck', ?, NOW())",
                'iiisi',
                [$handId, $seq, $h['bidWinnerSeat'], $uuidOf($h['bidWinnerSeat']), $h['winningBid']]
            );
        }

        // --- tricks + plays ---
        foreach ($h['tricks'] as $i => $t) {
            $trickNo = $i + 1;
            self::exec(
                $db,
                "INSERT INTO trick (hand_id, trick_no, leader_seat, winner_seat, counters)
                 VALUES (?, ?, ?, ?, ?)",
                'iiiii',
                [$handId, $trickNo, $t['leaderSeat'], $t['winnerSeat'], $t['counters']]
            );
            foreach ($t['plays'] as $k => $p) {
                self::exec(
                    $db,
                    "INSERT INTO trick_play (hand_id, trick_no, play_no, seat_no, card, is_winning_card)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    'iiiisi',
                    [$handId, $trickNo, $k + 1, $p['seat'], $p['card'], $p['seat'] === $t['winnerSeat'] ? 1 : 0]
                );
            }
        }

        // --- per-player detail ---
        for ($seat = 0; $seat < $g->players; $seat++) {
            $dealt  = $h['dealt'][$seat] ?? [];
            $draw   = $h['drawRecord'][$seat] ?? ['discards' => [], 'drawn' => []];
            $final  = $h['final'][$seat] ?? [];
            $meld   = $h['meldRecord'][$seat] ?? ['items' => [], 'total' => 0];
            self::exec(
                $db,
                "INSERT INTO hand_player
                   (hand_id, seat_no, user_uuid, dealt_json, discards_json, drawn_json,
                    final_json, draw_count, meld_json, meld_points)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                // hand_id,seat, uuid,dealt,discards,drawn,final, draw_count, meld_json, meld_points
                'iisssssisi',
                [
                    $handId, $seat, $uuidOf($seat),
                    self::j($dealt), self::j($draw['discards']), self::j($draw['drawn']),
                    self::j($final), count($draw['drawn']),
                    self::j($meld['items']), (int) $meld['total'],
                ]
            );
        }

        // --- per-side outcome + running totals ---
        $res     = $h['result'];
        $bidSide = $h['bidWinnerSideNo'];
        foreach ($res['sides'] as $sideNo => $srow) {
            self::exec(
                $db,
                "INSERT INTO hand_side
                   (hand_id, side_no, is_bidding_side, meld_points, counters_captured,
                    hand_score, score_after)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                'iiiiiii',
                [
                    $handId, $sideNo, $sideNo === $bidSide ? 1 : 0,
                    (int) $h['meldBySide'][$sideNo],
                    (int) $h['countersBySide'][$sideNo],
                    (int) $srow['handScore'],
                    (int) $g->running[$sideNo],
                ]
            );
            self::exec(
                $db,
                "UPDATE game_side SET score = ?, is_winner = ? WHERE game_id = ? AND side_no = ?",
                'iiii',
                [(int) $g->running[$sideNo], $g->gameWinnerSideNo === $sideNo ? 1 : 0, $gameId, $sideNo]
            );
        }

        if ($g->isGameOver()) {
            self::exec(
                $db,
                "UPDATE game SET status = 'complete', winning_side_no = ?, ended_at = NOW() WHERE id = ?",
                'ii',
                [$g->gameWinnerSideNo, $gameId]
            );
        }
    }

    // ---- transaction wrapper for API endpoints ----------------------------

    /**
     * Load the game, run $mutate($state) inside a transaction, save, and return
     * the viewer's snapshot. $mutate performs one validated engine action and
     * may throw DomainException — that rolls the transaction back.
     *
     * @param callable $mutate  fn(State $g): void
     * @return array  snapshot for $viewerUuid (own hand visible, others hidden)
     */
    public static function withGame(string $gameUuid, ?string $viewerUuid, callable $mutate): array
    {
        return pp_tx(static function (mysqli $db) use ($gameUuid, $viewerUuid, $mutate) {
            $g = self::load($db, $gameUuid);
            $mutate($g);
            self::save($db, $gameUuid, $g);
            return self::snapshotFor($g, $viewerUuid);
        });
    }

    /** State snapshot with the roster merged in and the viewer's hand revealed. */
    public static function snapshotFor(State $g, ?string $viewerUuid): array
    {
        $viewerSeat = null;
        foreach ($g->roster as $seat => $p) {
            if ($viewerUuid !== null && $p['uuid'] === $viewerUuid) {
                $viewerSeat = (int) $seat;
                break;
            }
        }

        $snap = $g->snapshot($viewerSeat);
        foreach ($snap['seats'] as $seat => &$s) {
            if (isset($g->roster[$seat])) {
                $s['uuid']       = $g->roster[$seat]['uuid'];
                $s['name']       = $g->roster[$seat]['name'];
                $s['isHost']     = $g->roster[$seat]['isHost'];
                $s['connection'] = $g->roster[$seat]['connection'];
            }
        }
        unset($s);

        $snap['viewerSeat'] = $viewerSeat;
        return $snap;
    }
}
