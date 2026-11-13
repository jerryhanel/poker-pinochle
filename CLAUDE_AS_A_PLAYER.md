# Claude as a Player — how to sit at a table through the HTTP API

**Written:** 2026-11-06 from reading `api/*.php`, `lib/Api.php`, `lib/GameStore.php`,
`lib/bootstrap.php`, `engine/State.php`. **Verified live on 2026-11-06:** the §2 helper,
login, lobby list, join, bid, draw, play and ready all worked against the real server in
a 2-player game (the phase `meld` never appears — it passes straight to `play`).

Companion to `stategies/level-1.md` (what to *decide*). This file is *how to act*.

**Fair-play rule:** I act only through the same endpoints a browser uses, as my own
seat. The snapshot only reveals my hand; I never read the DB, `State`, or another
seat's cards. The server is authoritative — every illegal move is rejected.

---

## 1. What I need from the user

| Need | Why |
|---|---|
| **Table PIN** | Login is name + shared PIN. **The PIN is `2468`** (Jerry told me to record it here). It also lives in `config.php`, which I don't need to read. |
| **Join code** (6 chars) or **game uuid** | The user creates the table in the browser and reads me the code, or I create it myself (§4). |
| Base URL | `https://haneldev.zapto.org/poker-pinochle` (from `config.sample.php`; cookie is `Secure`, so **https only**) |

Identity is just the name I log in with (uuid = hash of the lowercased name). I'll use
the name **`Claude`** unless told otherwise.

---

## 2. Connecting from PowerShell (each tool call is a fresh shell)

Shell state doesn't persist between calls, so everything lives in files: a curl cookie
jar and the CSRF token. Use `curl.exe` (not the `curl` alias). Send JSON bodies from a
**file** — PowerShell strips the quotes if JSON is passed as an argument.

Save this once as `<scratchpad>\pp.ps1` and dot-source it at the top of each call
(`. "<scratchpad>\pp.ps1"`):

```powershell
$Base = 'https://haneldev.zapto.org/poker-pinochle'
$Dir  = Join-Path $PSScriptRoot 'pp-state'
New-Item -ItemType Directory -Force $Dir | Out-Null
$Jar  = "$Dir\jar.txt"

function pp-login($name, $pin) {
  Remove-Item $Jar -ErrorAction SilentlyContinue
  $page = (curl.exe -s -c $Jar -b $Jar "$Base/") -join "`n"
  $csrf = [regex]::Match($page, 'name="csrf" value="([0-9a-f]+)"').Groups[1].Value
  curl.exe -s -o NUL -c $Jar -b $Jar -d "action=login" --data-urlencode "username=$name" `
    -d "pin=$pin" -d "csrf=$csrf" "$Base/"
  $me = curl.exe -s -b $Jar -c $Jar "$Base/api/me.php" | ConvertFrom-Json
  if (-not $me.signedIn) { throw 'Login failed (bad PIN or name?)' }
  Set-Content "$Dir\csrf.txt" $me.csrf
  $me
}
function pp-get($path) { curl.exe -s -b $Jar -c $Jar "$Base/api/$path" | ConvertFrom-Json }
function pp-post($endpoint, $body) {
  [IO.File]::WriteAllText("$Dir\body.json", ($body | ConvertTo-Json -Compress -Depth 6),
                          (New-Object Text.UTF8Encoding $false))
  $csrf = (Get-Content "$Dir\csrf.txt" -Raw).Trim()
  curl.exe -s -b $Jar -c $Jar -H "Content-Type: application/json" -H "X-PP-CSRF: $csrf" `
    --data-binary "@$Dir\body.json" "$Base/api/$endpoint" | ConvertFrom-Json
}
```

**Auth mechanics (from `bootstrap.php` / `_boot.php`):**
1. `GET /` sets the session cookie and prints a hidden `csrf` field.
2. `POST /` (form-encoded) `action=login&username=…&pin=…&csrf=…` → 302 + a regenerated session cookie.
3. `GET api/me.php` → `{signedIn, uuid, name, csrf}`. **The token to use for every POST comes from here**, sent as header `X-PP-CSRF` (or a `csrf` field in the JSON body).
4. Every response is JSON: `{"ok":true, …}` or `{"ok":false,"error":"…"}`.

| HTTP | Meaning |
|---|---|
| 200 | ok |
| 400 | bad input (missing/malformed game id, bad number) |
| 401 | not signed in — log in again |
| 403 | bad/missing CSRF — re-run `api/me.php`, refresh `csrf.txt` |
| 405 | wrong method (POST-only endpoints) |
| 409 | **illegal move / wrong phase / not your turn** — read `error`, don't blindly retry |
| 500 | server error |

---

## 3. Endpoint cheat-sheet

All POST bodies are JSON. `game` = the game uuid (`gameUuid`). Replies from game
actions return the **full snapshot** for me (§5), so I rarely need a separate GET.

| Endpoint | Method | Body / query | Notes |
|---|---|---|---|
| `me.php` | GET | — | Who am I + CSRF token |
| `lobby.php?action=list` | GET | — | `{games:[{gameUuid, mode, playerCount, seated, status, joinCode, viewers}]}` for `lobby`/`active` games |
| `lobby.php` `create` | POST | `{action:"create", players:2..8}` | Mode is derived: even & ≥4 → `teams`, else `cutthroat`. I become host in seat 0. Returns `{gameUuid, joinCode, seat}` |
| `lobby.php` `join` | POST | `{action:"join", code:"ABC123"}` or `{game:uuid}` | Takes the lowest free seat. Idempotent if already seated. Only while `status=lobby` |
| `lobby.php` `watch` | POST | `{action:"watch", code|game}` | Spectate only |
| `lobby.php` `leave` | POST | `{action:"leave", game}` | In a live game this marks me `left` |
| `game.php?g=<uuid>` | GET | — | Poll this. Full snapshot, only my hand revealed |
| `teamvote.php` | POST | `{game, picks:[uuid,…], lock:true}` | Teams mode, table full, before the first deal. See §4 |
| `start.php` | POST | `{game}` | **Host only.** Deals the first hand and every later hand |
| `bid.php` | POST | `{game, action:"bid"\|"pass", amount?}` | Auction |
| `declare.php` | POST | `{game, inSuit:"S"\|"H"\|"D"\|"C"\|"NS"}` | Bid winner only |
| `draw.php` | POST | `{game, discards:["9S","JD"]}` | `[]` = stand pat |
| `play.php` | POST | `{game, card:"TS"}` | One card per turn |
| `ready.php` | POST | `{game}` | After a hand is scored, every non-left seat readies up |
| `chat.php` | POST | `{game?, text}` | Table chat (omit `game` = lobby). GET `?g=&since=` reads |
| `presence.php` | POST | `{}` | Optional heartbeat for the Users menu |
| `bot_vote.php`, `bot_act.php`, `remove_bot.php`, `meet.php` | POST | — | Bots / meeting link. Not needed to play |

**Card codes:** rank + suit, e.g. `AS`, `TH` (**T = ten**), `9D`, `QC`. Ranks `9 T J Q K A`,
suits `S H D C`. There are two identical copies of every card, so a code can appear twice
in a hand — that's normal.

---

## 4. Getting to a dealt hand

**Case A — the user hosts and gives me a code (preferred):**
1. `pp-login Claude <PIN>`.
2. `pp-post lobby.php @{action='join'; code='ABC123'}` → I'm seated; note `seat` and `sideNo`.
3. **Teams mode only (4/6/8 players):** once the table is full the lobby needs everyone to lock a team pick.
   - Read `teamVoteNeeded`, and each seat's `uuid`/`name` from `pp-get "game.php?g=…"`.
   - `pp-post teamvote.php @{game=$g; picks=@('<teammate uuid>'); lock=$true}` — `picks` must be exactly `players/2 − 1` other seated uuids.
   - When all have locked, the server tallies, and if the proposals disagree it **randomises** the teams, then rewrites seats/sides. **Re-read my `viewerSeat` and `sideNo` afterwards** — they may have changed.
4. The host deals with `start.php`. I cannot.

**Case B — I host:** `pp-post lobby.php @{action='create'; players=2}` → give the user the `joinCode`; wait until `seated == playerCount`; (teams: vote); then `pp-post start.php @{game=$g}`.

*2 players* is cutthroat mode (no team vote, target 50, min bid 2). Teams need ≥4 and an even count.

---

## 5. Reading the snapshot (what a reply / `game.php` gives me)

| Field | Meaning |
|---|---|
| `viewerSeat` | **My seat number.** All "seat" fields below use it |
| `seats[i].cards` | My hand — **non-null only for my own seat**. Others show only `count` |
| `seats[i].{name, uuid, sideNo, isDealer, isBot, connection}` | Roster. `sideNo` = team (teams mode) |
| `phase` | `null` (lobby) → `auction` → `declare` → `draw` → `meld` → `play` → `scored` |
| `turnSeat` | Whose action the server is waiting on (auction/declare/play). `null` during `draw` |
| `auction` | `{order, pos, high:{seat,amount}\|null, log:[{seat,action,amount}], closed}` |
| `winningBid`, `bidWinnerSeat`, `bidWinnerSideNo`, `inSuit` | Set after the auction / declare |
| `keepMin` | Cards I must keep in the draw (max discard = 5 − keepMin) |
| `discarded` | Seats that have already discarded this round |
| `meld`, `meldBySide` | Everyone's meld, public once play starts — **read it for card tracking** |
| `trick` | `{leaderSeat, order, plays:[{seat,card}]}` — the trick in progress |
| `lastTrick`, `capturedBySide`, `tricksWonBySide`, `countersBySide`, `tricksPlayed` | Public history for counting |
| `legalPlays`, `legalPlayReason`, `suggestedPlay` | Present **only on my turn in `play`**. `legalPlays` is exactly what the server will accept; `suggestedPlay` is the engine's simple heuristic `{code, reason}` — a hint, not an order |
| `result` | On `scored`: made/set, `handScores`, `runningAfter` |
| `running`, `target`, `gameOver`, `gameWinner` | Score / game end |
| `ready`, `readyNeeded` | Post-hand ready-up status |
| `journal` | Past hands' score pad |

PHP encodes arrays with keys `0..n-1` as JSON **lists**, so `running`, `sides`, `meldBySide`
etc. index by side number as plain arrays.

---

## 6. Per-phase recipe

**Turn loop:** poll `game.php?g=<uuid>` once every **10 s** (Jerry's instruction; there is no
push for me, and the Soketi websocket is for browsers). Act only when the condition below holds, then poll again.

| Phase | Act when | Call | Expect |
|---|---|---|---|
| `auction` | `turnSeat == viewerSeat` | `bid.php {game, action:"pass"}` or `{action:"bid", amount:N}` | Legal `N` is `(high.amount+1 … 20)`, or `players … 20` if no bid yet. Bidding goes left of the dealer, dealer last. **All pass → dealer is stuck at the minimum.** A bad amount returns 409 with the legal range |
| `declare` | `bidWinnerSeat == viewerSeat` | `declare.php {game, inSuit}` | `S H D C` or `NS`. Moves straight to `draw` |
| `draw` | my seat not in `discarded` | `draw.php {game, discards:[…]}` | No turn order — everyone discards in parallel. Cards must be ones I hold; at most `5 − keepMin`. When the **last** seat discards, replacements are dealt, meld is computed, and the phase jumps to `play` in the same response — so re-read my hand then |
| `meld` | never | — | Automatic and instantaneous; nothing to send |
| `play` | `turnSeat == viewerSeat` | `play.php {game, card}` | Card must be in `legalPlays` (all-beat rules). The bidder leads trick 1; each trick's winner leads the next. After trick 5 the phase becomes `scored` |
| `scored` | always | `ready.php {game}`, then **host** calls `start.php` | Next hand deals only after every seat is ready. If `gameOver`, stop |

**Per-turn procedure I follow:**
1. `pp-get` the snapshot; rebuild the tracker (hand, cards seen, voids, counters).
2. Decide per `stategies/level-4.md`, and log each non-forced decision (§10 there).
3. Send one action; on 409, read the message, re-check `legalPlays`, and fix the choice — don't loop.
4. Poll again.

---

## 7. Gotchas

- **Only the host can `start`** the hand. If the user is host, I wait for them; if I am host, I deal.
- **Seat can change after the team vote** — don't cache `viewerSeat` from before it resolves.
- **Dealer rotates** each hand; bidding starts at the dealer's left. Read `isDealer` / `dealerSeat` fresh.
- **The draw is simultaneous.** I can't see what others pitch, only *who* has discarded. Draw counts aren't exposed.
- **Meld shows itself**; I never declare it. Use `meld` / `meldBySide` right after the draw to track opponents' cards and compute `Need = bid − team meld`.
- **I can't duck.** `legalPlays` already encodes forced beats and forced trumping; I choose among those.
- **A card code is not unique** (two copies). Playing `KS` plays one of them.
- **Realtime is not required.** Don't try the WebSocket; polling `game.php` is enough.
- **Don't spam.** A losing race or a stale poll just returns the current state; wait for the next poll rather than retrying a rejected move.
- **Only send the PIN to the login form** (`POST /`), and don't send the user's email anywhere. The PIN (`2468`) is recorded in this file at Jerry's explicit request.
- **Bots:** if a seat is `isBot`, a human's browser triggers its moves via `bot_act.php`. Only matters if the user adds bots.

---

## 8. Smoke test before a real game

1. `pp-login Claude <PIN>` → `signedIn: true`, a `csrf` string.
2. `pp-get "lobby.php?action=list"` → `ok:true` and a `games` array.
3. Join the user's code → `seat` returned; `pp-get game.php?g=…` shows `viewerSeat` equal to it and `seats[viewerSeat].cards == null` until a hand is dealt.
4. After the deal: 5 card codes in my hand, `phase == "auction"`, and `turnSeat` points at the dealer's left.

If any of these fail, fix the helper before playing.

---

## 9. Session notes (read these first in a fresh session)

**Working agreements with Jerry**
- **PIN: `2468`** (Jerry asked me to keep it in this file, so there is no need to ask each session). Log in as `Claude`: `pp-login 'Claude' '2468'`.
- **Poll once per 10 seconds**, not faster.
- **Explain each move before making it**: the hand, the reasoning, and which rule I'm following or deviating from.
- **Don't edit the docs until Jerry says so.** Evaluate first, then write. The current playbook is `stategies/level-4.md` (folder spelled "stategies"); it supersedes levels 1, 2 and 3. Level 4 has no opponent profile: play a sound baseline against anyone, and value every bid and draw in both roles (declarer and defender).
- **Jerry's rules are one school of play, not doctrine.** I may develop my own, but I label deviations and the reason.
- Play from my own hand and public information only. No reading the DB, `State`, or the deal.

**Where the game stood** *(verify against the server, since IDs go stale)*
- Game 1, 2 players, cutthroat, target 50. Jerry is seat 0 and the host, and only he can deal. I'm `Claude`, seat 1.
- Join code `6CBF6E`; game uuid `3c6c880a-c0a0-4fa8-8d91-01963995cdac`. Re-find it with `lobby.php?action=list`. Joining a game I'm already in simply returns my seat.
- After hand 2: Jerry 10, Claude 5, and I had already readied up for hand 3.

**Pitfalls I hit**
- `ready.php` only works in phase `scored`. After my own last play the phase is still `play` until the opponent's card lands, so wait for `scored` first.
- A reply to `play.php` shows only my action. Poll for the opponent's card.
- No browser tools were available. Everything is done through this API.

---

## 10. Rules refresher (the essentials, so no other file is needed)

- **Deck:** 48 cards: 9 T J Q K A, two copies of each in S H D C (`T` = ten). **Rank:** A > T > K > Q > J > 9. **Counters:** A, T, K = 1 point each (24 in the deck).
- **Mode:** an even count ≥ 4 is teams; anything else is cutthroat. **Target:** 2 players 50, 4 players 50, 6 players 150, 8 players 200 (cutthroat otherwise `25 × players`).
- **Hand flow:** deal 5 each, rest is the stock → **auction** (one trip, left of the dealer first, dealer last; pass or bid strictly higher, from `players` up to 20; all pass → dealer is stuck at the minimum) → winner **declares** `S/H/D/C` or `NS` (no suit) → **draw** (simultaneous; discard up to `5 − keepMin`, which is 5 for 2–4 players, 4 for 5, 3 for 6–8) → **meld** (automatic) → **5 tricks** → score.
- **Meld** (from the final 5 cards; meld stays in hand; a card may count in several melds; doubles score again):

  | Meld | Points |
  |---|:-:|
  | Marriage K+Q same suit: in the declared suit / otherwise (always 2 under NS) | 4 / 2 |
  | 9 in the declared suit (none under NS), each copy | 1 |
  | Pinochle Q♠ + J♦ | 4 |
  | Four aces / kings / queens / jacks, one of each suit | 10 / 8 / 6 / 4 |

- **Play:** the bid winner leads trick 1; each trick's winner leads the next. You must follow suit and beat the best card of the led suit if you can. If void in the led suit and a trump suit exists, you must trump (and over-trump if you can). Only if you can do neither may you sluff anything. Identical cards tie, and the earlier one wins. Under NS the highest card of the led suit wins.
- **Score:** a side with no tricks loses its meld. Bidding side: kept meld + counters ≥ bid → score the full total; otherwise **−bid** (meld lost). Other sides: kept meld + counters.
