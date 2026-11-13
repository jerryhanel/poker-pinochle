# Bot Seat — Implementation Plan

**Status:** designed, not yet built. Companion to `ARCHITECTURE.md` / `DESIGN.md`.

## Context

Any seated human (not just the host) can propose putting a bot in an open or
abandoned seat; if **every other currently-seated human unanimously votes
yes**, the bot is seated with a randomly chosen name (static list for v1; a
Claude-API naming call is a planned future upgrade, not built now). The bot's
actual moves (bid/declare/draw/play) are triggered by *any* seated player's
browser noticing it's the bot's turn during normal polling and firing a
backend request — no host restriction, since multiple browsers racing to
trigger the same bot turn is harmless (the endpoint no-ops if the bot has
already acted). Decision logic starts heuristic-only with a clean seam to swap
in a Claude-API strategy per decision type later.

Research confirmed:
- `GameStore::seatPlayer()` already accepts any synthetic `user_uuid` with zero
  auth checks — `dev.php`'s test harness proves this seating fake `Bot-B/C/D`
  players, and its `INSERT ... ON DUPLICATE KEY UPDATE (game_id, seat_no)`
  shape naturally handles both "fill an open seat" and "replace an abandoned
  seat" the same way.
- `game_player.connection` already tracks `'left'` for a human who
  disconnected mid-game (set by `Api::lobbyLeave`) — this is exactly how a
  mid-game bot-vote-eligible seat is detected.
- `State::suggestPlay()` (private, `engine/State.php:553-626`) **already
  implements** the exact play-phase heuristic wanted (lead/follow logic with
  reasons like `safe_sluff`, `cheapest_loss`, `lead_power`) — reuse via the
  existing public `State::snapshot()`, no engine changes needed.
- No heuristic exists yet for bid / declare-suit / draw decisions — new logic
  needed in a new file.
- `GameStore::teamVoteSubmit()` (`lib/GameStore.php:228-`) is an existing
  "everyone locks in, tally, auto-resolve" pattern for team pairing — the bot
  vote is modeled on this shape but per-seat and unanimous-yes/single-no-cancels
  rather than a multi-way tally.
- `GameStore::withGame()` remains the transaction wrapper every mutation uses
  (`load → mutate → save → snapshotFor`) — bot moves reuse it unchanged.

## Approach

### 1. Schema — `migrations/0010_bot_seats.sql` (manual step, `pp_user` can't ALTER)
```sql
ALTER TABLE game_player
  ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0 AFTER is_host;
ALTER TABLE game
  ADD COLUMN bot_votes_json MEDIUMTEXT NULL AFTER team_vote_json;
INSERT INTO schema_migrations (version) VALUES ('0010_bot_seats')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
```
`bot_votes_json` holds an object keyed by target seat number, e.g.
`{"3": {"proposedBy": "<uuid>", "ballots": {"<uuid>": true, ...}}}` — multiple
seats can have independent pending proposals at once.

### 2. `lib/GameStore.php`
- `seatPlayer()` (line 123): add `bool $isBot = false` param; include `is_bot`
  in the INSERT/ON-DUPLICATE column list.
- Roster mapping (~line 170): add `'isBot' => (bool) $r['is_bot']`.
- `snapshotFor()` (~line 668): add `$s['isBot']` alongside the existing
  `$s['isHost']`/`$s['connection']` — this is how `app.js` learns which seats
  are bots and which are abandoned (vote-eligible).
- New `botVoteSubmit(mysqli $db, int $gameId, array $roster, array $currentVotes, int $targetSeat, string $uuid, bool $vote): array`
  modeled on `teamVoteSubmit` (line 228): validate caller is seated and not the
  target seat; validate target seat is actually open (no roster entry) or
  abandoned (`connection === 'left'`); "needed" voters = every other currently
  `connected` human seat (excluding bots, excluding the target). Record
  `ballots[uuid] = vote`. If `vote === false`, clear that seat's whole proposal
  immediately (cancelled) — unanimity means one "no" kills it. If all needed
  voters are `true`, seat the bot (random name — see §3) via `seatPlayer(...,
  isBot: true)`, clear the vote entry, return `{resolved: true, botName}`. Else
  persist ballots, return `{resolved: false}`.

### 3. `lib/BotNames.php` (new)
A static array of ~40 fun bot names (card/poker-flavored). Single function
`BotNames::pick(): string` → `$names[array_rand($names)]`. Seam noted for a
future Claude-API-backed name generator (swap the function body only).

### 4. `lib/Api.php`
- New `botVote(array $user, array $a): array` — args `{game, seat, vote}`
  (`vote` omitted/true on first call = implicit yes-propose). No host check —
  any seated human may call this. Loads game/state, calls
  `GameStore::botVoteSubmit(...)`, `announce()`, returns snapshot +
  `{voteResolved, botName?}`.
- New `botAct(array $user, array $a): array` — args `{game, seat}`. Caller
  must be a *seated, non-bot* player in this game (no host restriction).
  Load state, verify `roster[$seat]['isBot']` true; per-phase "does it actually
  need to act" guard (`currentTurnSeat()` for auction/play, `hand['bidWinnerSeat']`
  for declare, `pendingDiscardSeats()` for draw) — false guard = harmless no-op,
  not an error (handles multi-browser races). Call
  `GameStore::withGame($uuid, $botUuid, function(State $g) use ($seat) {
  ...BotPlayer::decideX($g,$seat) then $g->bid/declareInSuit/draw/play(...)...
  })`; `announce()`; return snapshot.

### 5. `api/bot_vote.php` and `api/bot_act.php` (new, thin shims — mirror `api/teamvote.php`/`api/play.php`)
Standard `_boot.php` + `pp_api_need_post()` + `pp_api_csrf()` +
`pp_api_user()` + `pp_api_run(fn() => Api::botVote/botAct(...))`.

### 6. `lib/BotPlayer.php` (new)
`final class BotPlayer`, one static method per phase, called from inside
`Api::botAct()`'s `withGame` mutate closure (full server-side access to the
bot's real hand):

- **`decidePlay(State $g, int $seat): string`** — call `$g->snapshot($seat)`,
  read `['suggestedPlay']['code']`; fall back to `legalPlays[0]` if null.
  Zero engine changes needed.
- **`decideBid(State $g, int $seat): array`** — `{action, amount}`. Get
  `Auction::legalRange(...)`; null → pass. Strength = max over S/H/D/C/NS of
  `Meld::detect($hand,$suit)['total']`, plus `2 × counters held`. Threshold
  (tunable, e.g. 8) → bid legal minimum; else pass.
- **`decideDeclareSuit(State $g, int $seat): string`** — argmax
  `Meld::detect($hand,$suit)['total']` over S/H/D/C (tie-break: marriage >
  most cards held); 0 everywhere → `NS`.
- **`decideDraw(State $g, int $seat): array`** — keep marriage/pinochle/
  in-progress-four-of-a-kind cards and "protected" aces (same-suit backup
  card present); discard the rest, capped by `keepMin`.
- **`cardsSeen(State $g): array`** helper — flattens completed `hand['tricks']`
  + in-progress `hand['trick']['plays']` into "cards played this hand," reusing
  the same data `snapshot()` derives `capturedBySide` from.
- **Future-Claude seam**: each `decideX` thin-dispatches between
  `decideXHeuristic()` (built now) and a not-yet-built `decideXClaude()`.

### 7. `assets/app.js`
- New `maybeTriggerBot(snap)`, called at the tail of `refresh()`'s `.then()`
  for *any* seated (non-viewer, non-bot) player, gated on `!busy`: scan
  `snap.seats` for `isBot` seats needing to act (same phase logic as the
  server guard) and call `act(api('bot_act.php', {game: GAME, seat: botSeat}))`.
- New "Add Bot Here" control on any open seat slot (`feltLobbyWait`) or any
  abandoned seat (`connection !== 'connected'` mid-game, wherever seat status
  already renders) — visible to *every* seated player, not just host. Clicking
  proposes: `api('bot_vote.php', {game: GAME, seat: n})`. Once a proposal
  exists for a seat, every other seated player sees yes/no buttons there
  instead; clicking posts `{game, seat, vote: true|false}`.

## Critical Files
- `migrations/0010_bot_seats.sql` (new — manual DB step)
- `lib/BotNames.php` (new)
- `lib/GameStore.php` (`seatPlayer`, roster mapping, `snapshotFor`, `botVoteSubmit`)
- `lib/Api.php` (`botVote`, `botAct`)
- `api/bot_vote.php`, `api/bot_act.php` (new)
- `lib/BotPlayer.php` (new — heuristic decision engine)
- `assets/app.js` (`maybeTriggerBot`, per-seat vote UI)

## Verification
1. Apply the migration manually (`mysql -u root -p`).
2. Extend `dev.php`'s `Bot-B/C/D` harness with a `?botseat=1` block: 3 human
   fixtures + 1 open seat; drive `Api::botVote` from each human until unanimous,
   assert the bot lands with `isBot` true and a name from `BotNames`; assert a
   single `vote:false` cancels the proposal; `Api::start()`, then loop
   `Api::botAct` for the bot seat until the hand scores.
3. Mid-game abandonment case: seat 3 humans + 1 bot, have one human "leave"
   (`connection='left'`), propose+pass a vote to replace that seat with a
   second bot, confirm the game continues correctly with two bots.
4. Manual browser check: two human tabs, one proposes a bot, the other votes
   yes, confirm it seats; play a hand confirming either tab can trigger the
   bot's turn (open dev tools, watch only one `bot_act.php` call actually
   mutate state even if both tabs poll near-simultaneously).
