# Poker Pinochle — Build Handoff

**Date:** 2026-09-09
**Owner:** Jerry Hanel (jhanel@gmail.com) — address him as Jerry.
**State:** rules + schema + infra recon done. **No app code written yet.**
Ready to start building once the §6 blockers are answered.

This document is the cold-start entry point. Read it, then `DESIGN.md` (rules)
and `ARCHITECTURE.md` (stack). Everything factual below was verified this
session — don't re-derive it.

---

## 1. What we're building

A multiplayer online **Poker Pinochle** game — a fast pinochle variant with
5-card hands and a poker-style discard-and-draw after the auction. PHP + MySQL
backend, self-hosted Soketi (Pusher protocol) for realtime, `sgc-auth` for
player identity. Lives at `w:\poker-pinochle\` →
`https://haneldev.zapto.org/poker-pinochle/`.

Persistent tracking of games / hands / teams / per-player stats is a core goal
(schema already models it).

**Accessibility is a first-class requirement** — see §7.

---

## 2. Files in this folder

| File | Contents |
|---|---|
| `DESIGN.md` | Full game rules. §10 lists the rule questions still open. |
| `ARCHITECTURE.md` | Stack, topology, auth model, proposed file layout, open decisions (§7). |
| `migrations/0001_init.sql` | DB schema — **already applied** to the live `poker_pinochle` DB. |
| `HANDOFF.md` | This file. |

No code yet.

---

## 3. Confirmed infrastructure (verified — do not re-recon)

### Hosting
- `w:\` is the web root → `https://haneldev.zapto.org/`.
- App folder `w:\poker-pinochle\` → `https://haneldev.zapto.org/poker-pinochle/`.
- **PHP, and no Composer** on the NAS — core PHP only (`mysqli`, `curl`,
  `hash_hmac`, `session_*`).
- Same box and **same origin** as `w:\sgc-auth\` → include its lib directly, no
  HTTP, no CORS.

### Database
- **`poker_pinochle`** — MySQL runs on the NAS at **`192.168.8.218`**, reached
  over the LAN (NOT `localhost`; the web/PHP box is separate). `config.php`
  `['db']['host']` is set accordingly. `pp_user` is `@'%'` so remote is allowed.
  *(The `sgc-auth` lib include is still same-box on the web server — only MySQL
  is remote.)*
- App user **`pp_user`** / **`Tardaed!01`** (`@'%'`): `SELECT,INSERT,UPDATE,DELETE`
  on `poker_pinochle.*`, **`SELECT` on `sim_auth.*`**.
- `pp_user` **cannot** create schema — future migrations need a privileged user
  (`mysql -u root -p < migrations/000X.sql`).
- Tables (unprefixed): `game`, `game_side`, `game_player`, `hand`, `hand_side`,
  `hand_player`, `bid`, `trick`, `trick_play`, `schema_migrations`; view
  `v_player_stats`.
- **Card codes:** rank in `9 T J Q K A` (`T` = ten, keeps every card 2 chars),
  suit in `S H D C`. E.g. `"AS"`, `"TS"`, `"9H"`, `"QD"`.
- `game.rules_json` freezes the resolved rule toggles per match (shape
  documented in the migration file).

### Auth — `sgc-auth`
- Include `require_once '/w/sgc-auth/lib.php';` then:
  - `sgc_auth_connect()` → mysqli for `sim_auth`
  - `sgc_auth_get_user_by_uuid($db, $uuid)`
  - `sgc_auth_get_user_by_identifier($db, $identifier)` (email **or** username)
  - `sgc_auth_check_credentials($db, $identifier, $password)` → user row or false
  - `sgc_auth_is_banned($db, $uuid)` → `['isBanned'=>bool, 'banReason'=>?]`
- `sim_auth.sim_auth_user` columns: `user_uuid` (stable id we key on),
  `user_name`, `email`, `firstName`, `lastName`, `password` (**plaintext**),
  `isBanned`, `banReason`.
- **`sgc-auth` issues no session and no token for normal users.** Its HTTP API
  (`?action=user&op=login|get|check-ban|verify-token|logout`, CORS
  `*.perchance.org` only) is a stateless credential/uuid service. The ecosystem
  model is: client holds a `user_uuid` from Perchance's user plugin and every
  `sgc-*` service trusts it. Most users have **no password**.
- **We run our own PHP session**: `session_start()` → `$_SESSION['pp_uuid']`,
  `$_SESSION['pp_name']`. Re-check `sgc_auth_is_banned` on connect.

### Realtime — Soketi (self-hosted, Pusher protocol)
- Reference implementation of the trigger: `w:\soketi-test\publish.php`
  (hand-rolled Pusher HTTP trigger — `ksort` params, `body_md5`,
  `hash_hmac('sha256', "POST\n/apps/<appId>/events\n<qs>", $secret)`, curl).
- Existing app: server `192.168.8.218:6001` (LAN), app id `sgc-comments`,
  key `yupvcp2k16zz7wmvgw8ddk9a`, secret `69gb1js13vijzna9kl37tbxc`.
- **Client → server is never over Soketi** — all intents are authenticated HTTP
  POSTs to our PHP, which is the only writer, then broadcasts.
- Proposed channels: `presence-pp-game-<game_uuid>` (roster + all game events),
  `pp-chat-<game_uuid>`, `pp-lobby`.

---

## 4. Game rules — quick reference

Compressed; `DESIGN.md` is authoritative.

- Single 48-card pinochle deck (2× `9 T J Q K A` × 4 suits). Rank high→low
  **A T K Q J 9**. **Counters = A, T, K** (24 in the deck).
- Players up to ~8. **Even → two equal teams** (4 = 2+2, 6 = 3+3, 8 = 4+4);
  **odd → cutthroat**. Teams are chosen; seating **strictly alternates**
  between the two sides (A-B-A-B…).
- **Deal 5** each; the rest is a face-down **stock**.
- **Auction — one trip only.** Starts left of the dealer, dealer bids last.
  Each turn: pass, or name a number **strictly above the current high**, within
  **[#players … 20]**. High bid wins. **All pass → dealer stuck at #players.**
  Teams: each member bids on their own turn; the won bid belongs to the team.
- Winner names a **trump suit or "no trump"** (before the draw).
- **One discard-and-draw** from the stock, back up to 5. Keep ≥ **2** at 6
  players, ≥ **3** at 7–8. **Stock runs dry → reshuffle the discards.**
- **Meld** (from the post-draw 5-card hand; **counts toward the bid/target**):
  marriage K+Q **4 in trump / 2 otherwise**; 9 of trump **1**;
  pinochle Q♠+J♦ **4**; four aces (one per suit) **10**, four kings **8**,
  four queens **6**, four jacks **4**.
- **Play** 5 tricks. Must follow suit **and beat** if able; void → **must
  trump** and **must over-trump** if able; only if you can't follow/beat/trump
  may you **sluff**. Bid winner leads trick 1; each trick's winner leads next.
- **Score the hand:** bidding side scores **meld + counters captured** if that
  total **≥ bid** (may exceed it); if **< bid**, **forfeit all meld and score
  −(bid)**. **Everyone else scores 0** — non-winning bids are lost.
- **Game ends at:** 4p → **50**, 6p → **150**, 8p → **200**. Cutthroat (3p/5p)
  targets TBD.
- **Proposed defaults, not yet ratified:** stand-pat allowed (discard 0); may
  discard counters; draw order = bid-winner first then clockwise; reshuffle
  includes the current round's discards; "make" is `≥` (exact tie = made); no
  "must bid it out"; non-bidders don't lay meld (scores 0).

---

## 5. Suggested build order

**Phase 0 — unblock.** Get the §6 answers. Fold the ratified defaults into
`DESIGN.md` and a canonical `rules_json` example.

**Phase 1 — engine (pure PHP, no network, unit-testable).**
`engine/Cards.php`, `Deck.php` (CSPRNG shuffle), `Auction.php`, `Meld.php`,
`Trick.php` (strict all-beat legal-move validation + trick winner),
`Score.php`, `State.php`. Drive it with a CLI harness that plays a full hand
from a fixed deck seed and prints the score. Get this bulletproof first.

**Phase 2 — persistence.** `State.php` ↔ the `poker_pinochle` tables; game and
hand lifecycle; the "resync" snapshot a reconnecting client receives (own hand
visible, others' hidden).

**Phase 3 — HTTP API.** `api/_boot.php` (session, DB handles, sgc-auth include,
JSON helpers), `auth.php`, `lobby.php`, `game.php`, one endpoint per hand phase
(`bid` / `declare` / `draw` / `meld` / `play`), `chat.php`, `pusher_auth.php`.
Every endpoint: session + turn + phase check → validate via `engine/` →
persist → broadcast → return the new slice.

**Phase 4 — realtime.** `api/_pusher.php` (trigger, from the `soketi-test`
pattern); finalize the channel event schema; wire broadcasts into the phase
endpoints.

**Phase 5 — client.** Large-type table UI (§7), lobby, chat, render-from-state,
reconnect. Pusher JS client → our Soketi.

**Phase 6 — polish.** Per-turn timer + auto-action, disconnect/abandon
handling, spectators, profile/leaderboard pages off `v_player_stats`.

---

## 6. BLOCKERS — need Jerry's answers to start

1. **Delivery & entry.** Standalone site at `haneldev.zapto.org/poker-pinochle/`
   with a password login, **or** an embedded Perchance generator where the
   Perchance user plugin hands us `user_uuid` (trusted, like other `sgc-*`
   services), **or** both? Shapes the auth code and the client shell.
2. **Impersonation stance.** If we accept a client-supplied `user_uuid`, anyone
   can claim any identity (fine for a story generator, worse for ranked stats).
   Accept for v1, or require the password / email-token flow to open our
   session?
3. **Soketi.** Browser-reachable `wss://` URL (LAN `192.168.8.218:6001` won't
   work from users' browsers — reverse proxy or exposed port?). New dedicated
   Soketi app (own `appId`/key/secret), or reuse `sgc-comments`?
4. **Meld rules** (`DESIGN.md` §10.6/.7/.8) + **launch scope.**
   - Is the meld list complete (no A-T-K-Q-J run meld, no doubles)?
   - Can one card fill two different melds at once (Q♠ in a marriage *and* the
     pinochle)?
   - Must a side win at least one trick to keep its meld?
   - Launch with **4 & 6 players only**, defer 3/5-player cutthroat?

---

## 7. UI / accessibility requirement

**The primary player (Jerry's dad) has low vision.** Treat this as a spec
constraint:

- Cards **in hand** and **on the table** render at **≥ 24px** font — big
  rank + suit glyph tiles, **not** scaled-down playing-card art.
- Offer a per-client size bump (e.g. 24 / 32 / 40px) that persists in
  `localStorage`.
- High contrast: black/red suit on a plain light tile, thick borders, obvious
  selected / played / winning states.
- Hit targets ≥ 44px. Exactly one obvious primary action per turn.
  Unmistakable "it's your turn" cue.
- Bid, trump, and score status all in large type.
- Must be legible on a big monitor / TV at couch distance — test there.

---

## 8. Gotchas

- **No Composer** on the NAS — core PHP only.
- **Server is the only writer.** Never trust the client for hand contents,
  legal moves, or scoring.
- `pp_user` can't `CREATE` — schema migrations need a privileged MySQL user.
- Card code for ten is **`T`**, never `"10"` — keeps every card 2 chars.
- If the client ends up embedded in a Perchance generator: outside
  `<script>` / `<style>`, literal `[ ] { }` must be backslash-escaped (Perchance
  template engine parses them everywhere else). See the `scenario-generating-chat`
  project's `CLAUDE.md`.
- `sgc-auth` stores passwords in plaintext — don't log the returned user row.
- This folder is **not a git repo**. Consider `git init` in `w:\poker-pinochle\`
  before writing code. Commit attribution:
  `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`.

---

## 9. Open rule items parked in DESIGN.md §10

Beyond the §6 blockers, these are lower-stakes and can take the proposed
defaults unless Jerry says otherwise: dry-stock reshuffle scope, stand-pat,
draw-count visibility, discarding counters, draw order, whether non-bidders lay
meld, deal mechanics (cards-at-a-time, up-card, rotation), `≥` vs `>` for
making, "must bid it out", cutthroat targets, 2-player mode.
