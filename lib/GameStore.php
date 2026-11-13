<?php
declare(strict_types=1);

namespace PokerPinochle\Lib;

use DomainException;
use InvalidArgumentException;
use mysqli;
use mysqli_stmt;
use PokerPinochle\Engine\{Auction, Cards, Deck, Meld, Score, State};

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../engine/autoload.php';

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

    /**
     * Opportunistic housekeeping: flip stale lobby/active games to 'abandoned'
     * so they stop cluttering the table list. A status flip, not a DELETE — a
     * game already has hand/bid/trick rows some clients may still hold a
     * snapshot for, and 'complete' games (real history) are never touched.
     * Cheap (indexed on created_at) and safe to call on every lobby-list hit.
     */
    public static function pruneStale(mysqli $db, int $hours = 24): void
    {
        self::exec(
            $db,
            "UPDATE game
               SET status = 'abandoned', ended_at = NOW()
             WHERE status IN ('lobby','active')
               AND created_at < (NOW() - INTERVAL ? HOUR)",
            'i',
            [$hours]
        );
    }

    public static function seatPlayer(
        mysqli $db,
        int $gameId,
        int $seat,
        int $sideNo,
        string $uuid,
        string $name,
        bool $isHost = false,
        bool $isBot = false
    ): void {
        self::exec(
            $db,
            "INSERT INTO game_player
               (game_id, seat_no, side_no, user_uuid, display_name, is_host, is_bot, connection, joined_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'connected', NOW())
             ON DUPLICATE KEY UPDATE
               side_no = VALUES(side_no), user_uuid = VALUES(user_uuid),
               display_name = VALUES(display_name), is_host = VALUES(is_host),
               is_bot = VALUES(is_bot), connection = 'connected', left_at = NULL",
            'iiissii',
            [$gameId, $seat, $sideNo, $uuid, $name, $isHost ? 1 : 0, $isBot ? 1 : 0]
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

    /**
     * $hostUuid (game.host_user_uuid) is the authority for "isHost" — NOT the
     * per-row is_host column, which only reflects whatever was true at the
     * moment that particular game_player row was inserted. Any reseat that
     * goes through seatPlayer() without isHost:true (lobbyJoin(), a bot-vote
     * refill after removeBot()'s misdeal, etc.) would otherwise silently
     * "lose" the host flag even though game.host_user_uuid never changed —
     * comparing against it directly here means the host badge/permissions
     * can never drift out of sync with who can actually call Api::start().
     */
    private static function roster(mysqli $db, int $gameId, string $hostUuid): array
    {
        $out = [];
        foreach (self::rows(
            $db,
            "SELECT seat_no, side_no, user_uuid, display_name, is_bot, connection
             FROM game_player WHERE game_id = ? ORDER BY seat_no",
            'i',
            [$gameId]
        ) as $r) {
            $out[(int) $r['seat_no']] = [
                'uuid'       => $r['user_uuid'],
                'name'       => $r['display_name'],
                'sideNo'     => (int) $r['side_no'],
                'isHost'     => $r['user_uuid'] === $hostUuid,
                'isBot'      => (bool) $r['is_bot'],
                'connection' => $r['connection'],
            ];
        }
        return $out;
    }

    /** @return array<int,array{uuid:string,name:string}> list, insertion order */
    private static function viewers(mysqli $db, int $gameId): array
    {
        $out = [];
        foreach (self::rows(
            $db,
            "SELECT user_uuid, display_name FROM game_viewer WHERE game_id = ? ORDER BY joined_at",
            'i',
            [$gameId]
        ) as $r) {
            $out[] = ['uuid' => $r['user_uuid'], 'name' => $r['display_name']];
        }
        return $out;
    }

    /** Start (or resume) spectating. Idempotent — re-watching just refreshes the name. */
    public static function addViewer(mysqli $db, int $gameId, string $uuid, string $name): void
    {
        self::exec(
            $db,
            "INSERT INTO game_viewer (game_id, user_uuid, display_name, joined_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE display_name = VALUES(display_name)",
            'iss',
            [$gameId, $uuid, $name]
        );
    }

    public static function removeViewer(mysqli $db, int $gameId, string $uuid): void
    {
        self::exec($db, "DELETE FROM game_viewer WHERE game_id = ? AND user_uuid = ?", 'is', [$gameId, $uuid]);
    }

    /**
     * Submit (and optionally lock) $uuid's team-pairing proposal for a
     * teams-mode lobby that's already full. $currentVote is the decoded
     * game.team_vote_json ([] if none yet): {resolved, method, ballots}.
     *
     * Once every seated player is locked, resolves immediately: each
     * player's proposal is normalized to a 2-way split of every seated uuid
     * (their picks + themself vs. everyone else); the split with the most
     * agreeing proposals wins. A tie at the top randomizes the split
     * instead. The winning split is then written straight to
     * game_player.seat_no/side_no — side 0 takes the even seats, side 1 the
     * odd ones, each side's members placed in order of their prior seat_no.
     *
     * @param array<int,array{uuid:string}> $roster  seat_no => roster row (self::roster())
     * @param array{resolved?:bool,method?:?string,ballots?:array} $currentVote
     * @param string[] $picks  exactly (players/2 - 1) other seated uuids
     * @return array{resolved:bool, method:?string}
     */
    public static function teamVoteSubmit(
        mysqli $db,
        int $gameId,
        array $roster,
        array $currentVote,
        string $uuid,
        array $picks,
        bool $lock
    ): array {
        $players     = count($roster);
        $teamSize    = intdiv($players, 2);
        $seatedUuids = array_map(static function ($p) { return $p['uuid']; }, $roster);

        if (!in_array($uuid, $seatedUuids, true)) {
            throw new DomainException('You are not seated in this game.');
        }

        $ballots = $currentVote['ballots'] ?? [];
        if (!empty($ballots[$uuid]['locked'])) {
            throw new DomainException('You already locked in your pick.');
        }

        $picks = array_values(array_unique($picks));
        foreach ($picks as $p) {
            if ($p === $uuid) {
                throw new DomainException("Pick your teammate(s) — your own seat is automatic.");
            }
            if (!in_array($p, $seatedUuids, true)) {
                throw new DomainException('Pick must be one of the seated players.');
            }
        }
        $need = $teamSize - 1;
        if (count($picks) !== $need) {
            throw new DomainException(
                "Pick exactly {$need} teammate" . ($need === 1 ? '' : 's') . ' (you sent ' . count($picks) . ').'
            );
        }

        $ballots[$uuid] = ['picks' => $picks, 'locked' => $lock];

        $allLocked = true;
        foreach ($seatedUuids as $su) {
            if (empty($ballots[$su]['locked'])) {
                $allLocked = false;
                break;
            }
        }

        if (!$allLocked) {
            $next = ['resolved' => false, 'method' => null, 'ballots' => $ballots];
            self::exec($db, "UPDATE game SET team_vote_json = ? WHERE id = ?", 'si', [self::j($next), $gameId]);
            return ['resolved' => false, 'method' => null];
        }

        // --- everyone's locked: tally each proposal's 2-way split ---
        $tally = [];   // canonical signature => {count, teams: [[uuid,...], [uuid,...]]}
        foreach ($seatedUuids as $su) {
            $teamA = array_merge([$su], $ballots[$su]['picks']);
            sort($teamA);
            $teamB = array_values(array_diff($seatedUuids, $teamA));
            sort($teamB);
            $pair = [$teamA, $teamB];
            usort($pair, static function ($x, $y) { return implode(',', $x) <=> implode(',', $y); });
            $sig = implode('|', array_map(static function ($t) { return implode(',', $t); }, $pair));
            if (!isset($tally[$sig])) {
                $tally[$sig] = ['count' => 0, 'teams' => $pair];
            }
            $tally[$sig]['count']++;
        }
        uasort($tally, static function ($a, $b) { return $b['count'] <=> $a['count']; });
        $ranked = array_values($tally);

        $method = 'vote';
        if (count($ranked) > 1 && $ranked[0]['count'] === $ranked[1]['count']) {
            $method   = 'random';
            $shuffled = $seatedUuids;
            shuffle($shuffled);
            $teams = [array_slice($shuffled, 0, $teamSize), array_slice($shuffled, $teamSize)];
        } else {
            $teams = $ranked[0]['teams'];
        }

        // side 0 <- teams[0] on the even seats, side 1 <- teams[1] on the odd
        // seats; within a side, keep members in their prior seat-number order.
        $seatOfUuid = [];
        foreach ($roster as $seatNo => $p) {
            $seatOfUuid[$p['uuid']] = $seatNo;
        }
        $assignments = [];   // uuid => [newSeat, newSide]
        foreach ($teams as $sideNo => $members) {
            usort($members, static function ($a, $b) use ($seatOfUuid) {
                return $seatOfUuid[$a] <=> $seatOfUuid[$b];
            });
            foreach ($members as $i => $memberUuid) {
                $assignments[$memberUuid] = [$i * 2 + $sideNo, $sideNo];
            }
        }

        // two-phase rewrite: bump everyone out of the 0..7 range first so the
        // (game_id, seat_no) primary key never collides mid-permutation.
        foreach (array_keys($assignments) as $memberUuid) {
            self::exec(
                $db,
                "UPDATE game_player SET seat_no = seat_no + 100 WHERE game_id = ? AND user_uuid = ?",
                'is',
                [$gameId, $memberUuid]
            );
        }
        foreach ($assignments as $memberUuid => [$newSeat, $newSide]) {
            self::exec(
                $db,
                "UPDATE game_player SET seat_no = ?, side_no = ? WHERE game_id = ? AND user_uuid = ?",
                'iiis',
                [$newSeat, $newSide, $gameId, $memberUuid]
            );
        }

        $final = ['resolved' => true, 'method' => $method, 'ballots' => $ballots];
        self::exec($db, "UPDATE game SET team_vote_json = ? WHERE id = ?", 'si', [self::j($final), $gameId]);

        return ['resolved' => true, 'method' => $method];
    }

    /**
     * Submit $uuid's yes/no vote on seating a bot in $targetSeat (an open
     * lobby seat, or a seat abandoned mid-game — connection = 'left').
     * $currentVotes is the decoded game.bot_votes_json ([] if none yet),
     * keyed by seat number (string keys, since it round-trips through JSON):
     *   {"<seat>": {"proposedBy": "<uuid>", "ballots": {"<uuid>": bool}}}
     *
     * Unanimous: every OTHER currently-connected human (non-bot) seat must
     * vote true. A single false vote cancels that seat's whole proposal
     * immediately — unanimity means one "no" can never be outvoted, so there's
     * nothing to keep pending. On full agreement, seats the bot right here
     * (random name via BotNames::pick(), synthetic uuid via pp_uuid_for_bot())
     * and clears the vote entry.
     *
     * @param array<int,array> $roster  seat_no => roster row (self::roster())
     * @return array{resolved:bool, cancelled:bool, botName:?string}
     */
    public static function botVoteSubmit(
        mysqli $db,
        int $gameId,
        int $players,
        string $mode,
        array $roster,
        array $currentVotes,
        int $targetSeat,
        string $uuid,
        bool $vote
    ): array {
        if ($targetSeat < 0 || $targetSeat >= $players) {
            throw new InvalidArgumentException('Bad seat number.');
        }

        $callerSeat = null;
        foreach ($roster as $seatNo => $p) {
            if ($p['uuid'] === $uuid) {
                $callerSeat = (int) $seatNo;
                break;
            }
        }
        if ($callerSeat === null) {
            throw new DomainException('You are not seated in this game.');
        }
        if ($callerSeat === $targetSeat) {
            throw new DomainException('You cannot vote on your own seat.');
        }

        $target = $roster[$targetSeat] ?? null;
        if ($target !== null && ($target['connection'] ?? 'connected') !== 'left') {
            throw new DomainException('That seat is already occupied.');
        }

        // needed voters = every OTHER currently-connected human (non-bot) seat
        $needed = [];
        foreach ($roster as $seatNo => $p) {
            if ((int) $seatNo === $targetSeat || !empty($p['isBot'])) {
                continue;
            }
            if (($p['connection'] ?? 'connected') === 'left') {
                continue;
            }
            $needed[] = $p['uuid'];
        }
        if (!in_array($uuid, $needed, true)) {
            throw new DomainException('You are not eligible to vote on this seat.');
        }

        $key   = (string) $targetSeat;
        $entry = $currentVotes[$key] ?? ['proposedBy' => $uuid, 'ballots' => []];
        $entry['ballots'][$uuid] = $vote;

        if ($vote === false) {
            unset($currentVotes[$key]);
            self::exec($db, "UPDATE game SET bot_votes_json = ? WHERE id = ?", 'si', [self::j($currentVotes), $gameId]);
            return ['resolved' => false, 'cancelled' => true, 'botName' => null];
        }

        $allYes = true;
        foreach ($needed as $nu) {
            if (empty($entry['ballots'][$nu])) {
                $allYes = false;
                break;
            }
        }

        if (!$allYes) {
            $currentVotes[$key] = $entry;
            self::exec($db, "UPDATE game SET bot_votes_json = ? WHERE id = ?", 'si', [self::j($currentVotes), $gameId]);
            return ['resolved' => false, 'cancelled' => false, 'botName' => null];
        }

        $botUuid = pp_uuid_for_bot($gameId, $targetSeat);
        $botName = BotNames::pick();
        $sideNo  = $mode === 'teams' ? $targetSeat % 2 : $targetSeat;
        self::seatPlayer($db, $gameId, $targetSeat, $sideNo, $botUuid, $botName, false, true);

        unset($currentVotes[$key]);
        self::exec($db, "UPDATE game SET bot_votes_json = ? WHERE id = ?", 'si', [self::j($currentVotes), $gameId]);

        return ['resolved' => true, 'cancelled' => false, 'botName' => $botName];
    }

    /** Host sets or clears the table's meeting link (e.g. a Google Meet URL). */
    public static function setMeetUrl(mysqli $db, int $gameId, ?string $url): void
    {
        self::exec($db, "UPDATE game SET meet_url = ? WHERE id = ?", 'si', [$url, $gameId]);
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
        $g->roster    = self::roster($db, $gameId, (string) $game['host_user_uuid']);
        $g->viewers   = self::viewers($db, $gameId);
        $g->journal   = self::journal($db, $gameId);
        $g->joinCode  = $game['join_code'] ?? null;
        $g->meetUrl   = $game['meet_url'] ?? null;
        $tv = !empty($game['team_vote_json']) ? json_decode((string) $game['team_vote_json'], true) : null;
        $g->teamVote  = is_array($tv) ? $tv : [];
        $bv = !empty($game['bot_votes_json']) ? json_decode((string) $game['bot_votes_json'], true) : null;
        $g->botVotes  = is_array($bv) ? $bv : [];
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
            "SELECT h.hand_no, h.bid_winner_seat, h.bid_winner_side_no, h.winning_bid, h.bid_made, h.in_suit,
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
                    'inSuit'     => $r['in_suit'],
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
            "INSERT INTO hand (game_id, hand_no, dealer_seat, phase, in_suit, deck_json, state_json, started_at)
             VALUES (?, ?, ?, 'auction', NULL, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               dealer_seat = VALUES(dealer_seat), phase = 'auction', in_suit = NULL,
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
               phase = ?, in_suit = ?, bid_winner_seat = ?, bid_winner_side_no = ?,
               winning_bid = ?, bid_made = ?, state_json = ?,
               scored_at = IF(? = 'scored', COALESCE(scored_at, NOW()), scored_at)
             WHERE id = ?",
            'ssiiiissi',
            [
                $h['phase'], $h['inSuit'], $h['bidWinnerSeat'], $h['bidWinnerSideNo'],
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
        $roster = $g->roster ?: self::roster(
            $db,
            $gameId,
            (string) ($db->query("SELECT host_user_uuid FROM game WHERE id = {$gameId}")->fetch_assoc()['host_user_uuid'] ?? '')
        );
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
            // bots don't linger "connected" at a finished table — same
            // connection/left_at shape a human leaving gets (Api::lobbyLeave)
            self::exec(
                $db,
                "UPDATE game_player SET connection = 'left', left_at = NOW() WHERE game_id = ? AND is_bot = 1",
                'i',
                [$gameId]
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
                $s['isBot']      = $g->roster[$seat]['isBot'];
                $s['connection'] = $g->roster[$seat]['connection'];
            }
        }
        unset($s);

        $snap['viewerSeat'] = $viewerSeat;
        return $snap;
    }
}
