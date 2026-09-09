# Poker Pinochle — Architecture

**Status:** draft from infra recon (2026-09-09). Decisions still open in §7.
Companion to `DESIGN.md` (the game rules).

Stack is settled: **PHP + MySQL**, matching `sgc-auth` / `sgc-core`. No build
step, no persistent socket server of our own. Client actions POST to PHP
endpoints; PHP validates → writes `poker_pinochle` → fires a realtime event.

---

## 1. Topology

```
Browser client  ──HTTPS──>  haneldev.zapto.org/poker-pinochle/   (our PHP + static client)
      │                            │
      │                            ├── require '/w/sgc-auth/lib.php'   (same box, in-process)
      │                            ├── MySQL  poker_pinochle  (pp_user, CRUD)
      │                            └── MySQL  sim_auth        (pp_user, SELECT only)
      │
      └──WebSocket──>  Soketi (Pusher protocol)   ← realtime table + chat
                            ▲
   our PHP ──HMAC HTTP trigger──┘   (publish events; pattern from soketi-test/publish.php)
```

- `w:\` is the web root → app URL `https://haneldev.zapto.org/poker-pinochle/`.
- Our PHP is **same-origin with `sgc-auth`** (both `haneldev.zapto.org`), so we
  call `sgc-auth`'s library functions directly — no CORS, no HTTP hop.

---

## 2. Realtime — Soketi (self-hosted, Pusher protocol)

Confirmed from `w:/soketi-test/publish.php`:

| | value |
|---|---|
| Server | `192.168.8.218:6001` (LAN address of the NAS) |
| Existing app | `sgc-comments`, key `yupvcp2k16zz7wmvgw8ddk9a`, secret `69gb1js13vijzna9kl37tbxc` |
| Trigger API | Pusher HTTP trigger **reimplemented by hand** — `hash_hmac` + curl, no Composer (not available on the NAS) |

We reuse the same hand-rolled trigger approach. Publishing from PHP:
`POST http://<soketi-host>:<port>/apps/<appId>/events` with the ksort'd
`auth_key` / `auth_timestamp` / `auth_version` / `body_md5` query params and an
`auth_signature = hash_hmac('sha256', "POST\n/apps/<appId>/events\n<qs>", secret)`.

### Channels
- `presence-pp-game-<game_uuid>` — seat roster, connection state, all game
  events (deal, auction turn, declare, draw, meld reveal, card played, trick
  won, hand scored, game over). Presence membership = who's actually at the
  table.
- `pp-chat-<game_uuid>` — in-table chat.
- `pp-lobby` — open-table list + global lobby chat (if we do a public lobby).

### Client → server is **never** over Soketi
All player intents (place bid, choose trump, discard, play card, send chat) are
authenticated **HTTP POSTs to our PHP**. PHP is the only writer; it then
broadcasts the resulting state change on the channel. Clients treat Soketi
messages as read-only truth from the server.

### Open (see §7)
- Public reachable Soketi URL for browsers (`192.168.8.218:6001` is LAN-only —
  need the WAN host/port or a `wss://haneldev.zapto.org/...` reverse proxy).
- Dedicated Soketi **app** for poker-pinochle (own `appId`/key/secret) vs.
  reusing `sgc-comments`.
- Presence/private channel auth endpoint (`/pusher/auth`) signed with our
  session identity.

---

## 3. Auth & identity

### What `sgc-auth` actually provides
Read of `w:/sgc-auth/index.php` + `lib.php`:

- **No sessions or tokens for normal users.** `POST ?action=user&op=login`
  (`identifier` + `password`) is a stateless credential check that returns the
  user record (it even returns the plaintext `password` field). `op=verify-token`
  is the email-link claim flow. `op=check-ban`, `op=get` take a `user_uuid`.
- The real ecosystem model: **the client holds a `user_uuid`** (minted by
  Perchance's user plugin), stores it locally, and passes it to every `sgc-*`
  service, which *trusts* it. Password / email-token flows exist only to
  recover or claim that uuid on a new device.
- Most users have **no password set** — `op=register` doesn't insert one; only
  `import-users` and the username/password claim flow do.

### How we use it
Because we're same-origin and on the same box:

```php
require_once '/w/sgc-auth/lib.php';
$authDb = sgc_auth_connect();                      // sim_auth
$user   = sgc_auth_get_user_by_uuid($authDb, $uuid);
$ban    = sgc_auth_is_banned($authDb, $uuid);
// password path, if used:
$user   = sgc_auth_check_credentials($authDb, $identifier, $password);
```

We also have direct `SELECT` on `sim_auth.*` via `pp_user` as a fallback.

### Our session
`sgc-auth` won't hold a session for us, so **our app runs its own**:
`session_start()` → `$_SESSION['pp_uuid']`, `$_SESSION['pp_name']`. Established
by whichever entry path §7 picks. Every game/chat endpoint requires it and
re-checks `sgc_auth_is_banned` on connect.

### Known risk (matches existing ecosystem, flag for v1)
If we accept a caller-supplied `user_uuid` (the Perchance-embed model), a client
can assert any uuid = impersonation. Acceptable for the story generator; higher
stakes here (competitive stats, ratings). Mitigations if wanted: require the
password/email-token flow to open our session; or have the embedding Perchance
page pass a short-lived signed handshake. **Decision in §7.**

---

## 4. Backend structure (PHP)

```
poker-pinochle/
  index.html            # client shell (static)
  app.js  app.css        # client
  api/
    _boot.php            # session, DB handles, sgc-auth include, helpers, JSON out
    _pusher.php          # hand-rolled Soketi trigger (from soketi-test pattern)
    auth.php             # login / establish session / logout / me
    lobby.php            # create game, list open games, join by code, leave
    game.php             # get full game state (resync / reconnect)
    bid.php  declare.php  draw.php  meld.php  play.php   # one per hand phase
    chat.php             # post chat message → broadcast
    pusher_auth.php       # presence/private channel auth
  engine/
    Deck.php  Cards.php   # 48-card deck, shuffle, card codes
    Auction.php           # one-trip bid validation, all-pass → dealer stuck
    Meld.php              # meld detection + scoring (rules from DESIGN §10.6-.8)
    Trick.php             # strict all-beat legal-move validation, trick winner
    Score.php             # meld+counters, make/set, running totals, game end
    State.php             # load/advance/persist a game's authoritative state
  migrations/
    0001_init.sql         # (applied)
  DESIGN.md  ARCHITECTURE.md
```

- **Server is authoritative for everything**: shuffle (CSPRNG), what each seat
  holds, every legal-move check, all scoring. The client is a renderer.
- Each phase endpoint: check session + turn + phase → validate the intent
  against `engine/` → persist → `_pusher.php` broadcast → return new state slice.
- Reconnect = `GET game.php` returns the full state the caller is allowed to see
  (own hand hidden from others), then client resubscribes to the channel.

---

## 5. Data access

- `poker_pinochle` — schema per `migrations/0001_init.sql`. `pp_user` /
  `Tardaed!01` @ `localhost`, full CRUD.
- `sim_auth` — `SELECT` only, for names / ban status.
- One authoritative row-set per game (`game`, `game_side`, `game_player`,
  `hand`, `hand_side`, `hand_player`, `bid`, `trick`, `trick_play`). In-flight
  hand state that doesn't need its own column lives in a working JSON blob —
  **open (§7):** add a `hand.state_json` working column, or keep a `game.state_json`
  snapshot, vs. reconstructing from the normalized rows each request.
- `rules_json` on `game` freezes the resolved rule toggles per match.

---

## 6. Client

- Static `index.html` + `app.js` + `app.css`, served from the app folder.
- Pusher JS client pointed at our Soketi; subscribes to the game presence
  channel + chat channel.
- Renders from server state only; every action is an HTTP POST that returns the
  updated slice (and the broadcast confirms it for everyone).
- Could also be wrapped as a Perchance generator (see §7).

### Accessibility — large-type table (hard requirement)
The primary player has low vision. This is a design constraint, not a
nice-to-have:

- Cards **in hand** and **on the table** render at **≥ 24px** font — large
  rank + suit glyph tiles, not scaled-down playing-card artwork.
- High contrast: black/red suits on a plain light tile, thick borders, clear
  selected/played states.
- Hit targets ≥ 44px; one obvious primary action per turn; unmistakable
  "it's your turn" cue.
- Score area and bid/trump status in large type too.
- Must be legible on a big monitor / TV at couch distance — test there, not
  just on a laptop. Consider a per-client text-size bump (24 / 32 / 40px).

---

## 7. Open decisions

### Delivery & entry (the big one)
How do players reach the game and get identity?
1. **Standalone** at `haneldev.zapto.org/poker-pinochle/` with an email/username
   + password login (sgc-auth credentials). Needs users to have claimed a
   password first — most haven't.
2. **Embedded Perchance generator** (`perchance.org/poker-pinochle`) — client
   loads there, Perchance user plugin supplies `user_uuid`, our backend trusts
   it like other `sgc-*` services.
3. **Hybrid** — embed for identity handoff, standalone URL still playable.

### Auth hardening
Accept caller-supplied `user_uuid` (ecosystem default), or require the
password / email-token flow to open our session, or a signed handshake from the
embedding page? (See §3 risk.)

### Soketi
- Public `wss` URL for browser clients (reverse proxy vs. exposed port).
- New dedicated app (`appId`/key/secret) or reuse `sgc-comments`.

### State persistence
`hand.state_json` working column vs. `game.state_json` snapshot vs. pure
reconstruction from normalized rows.

### Still-open game rules (block the meld engine, from `DESIGN.md §10`)
- 10.6 meld list complete? (no run meld, no doubles)
- 10.7 one card usable in two different melds at once?
- 10.8 must a side win a trick to keep its meld?
- Launch player counts: 4 & 6 only, defer 3/5 cutthroat?
