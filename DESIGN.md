# Poker Pinochle

**Status:** rules-capture in progress — not yet built.
**Last updated:** 2026-09-08

A small, fast pinochle variant: 5-card hands, a poker-style discard-and-draw after
the auction, and a bid you make by capturing counters in tricks. Meant to be a
multiplayer online game under `w:/poker-pinochle`, using `sgc-auth` for identity
and persistent tracking of hands / games / partnerships.

---

## 1. Setup

### Deck
Single 48-card pinochle deck — two copies each of **9, 10, J, Q, K, A** in all
four suits (♠ ♥ ♦ ♣). 48 cards total.

### Card rank (high → low, in trick play)
**A, 10, K, Q, J, 9**  — the 10 outranks the King, standard pinochle.

### Counters
**Aces, Tens, and Kings are counters.** 24 counters in the deck. Capturing
counters in tricks is how you fulfil a bid and how you score.

### Players and sides
- Up to **6 players** at the practical cap (see the draw-stock table in §5).
  8-player is referenced in the game-end targets; whether it is genuinely
  supported is **OPEN** (§8).
- **Even count → two equal teams** of `players / 2` each. 4 players → 2 teams of
  2; 6 players → 2 teams of 3; 8 players → 2 teams of 4. **Always two teams**,
  never three.
- Teams are *chosen*, not seat-assigned ("I'll be dad's partner"). Once teams are
  set, seating **strictly alternates** between the two teams around the table
  (A-B-A-B-A-B…), so no two teammates ever sit adjacent.
- **Odd count → cutthroat** — every player for themselves.

---

## 2. Hand flow (overview)

1. Deal 5 cards each; remainder becomes the face-down **stock**.
2. **Auction** — one trip around the table.
3. **Declare** trump suit or "no trump".
4. **Draw** — one discard-and-draw from the stock.
5. **Meld** — reveal scoring combinations.
6. **Play** — 5 tricks, strict "all-beat" rules.
7. **Score** the hand.

---

## 3. The auction

- **One trip only.** No second round.
- Bidding starts with the player **left of the dealer** and proceeds clockwise;
  the **dealer bids last**.
- On your turn you either **pass** or state a number that is **strictly higher
  than the current high bid**, within the range **[number-of-players … 20]**.
- Highest bid wins.
- If **everyone passes**, the **dealer is stuck** with a bid equal to the
  **number of players** (the minimum).

### Team bidding
Each team member bids on their **own** turn, so a team of 2 gets 2 cracks at
raising, a team of 3 gets 3, etc. Once the auction closes, the winning bid
belongs to the **team**, not the individual — the whole team is "on the bid" and
pools the meld and captured counters of **all** its members toward the target
(§7).

---

## 4. Declaring trump

Immediately after the auction, the winning bidder names either a **trump suit**
or **"no trump."**

Trump is known **before the draw**, so players can draw toward it.

Under a **no-trump** contract there is no trumping in play (a player void in the
led suit simply plays any card), all marriages meld as 2, and there is no
9-of-trump meld. *(Confirm — §8.)*

---

## 5. The draw (discard-and-draw)

Each player gets **one** chance to discard cards and draw replacements back up to
a 5-card hand, from the stock.

### Keep requirement (stock conservation)
| Players | Must keep ≥ | May discard ≤ |
|--------:|:-----------:|:-------------:|
| 2–5     | (none — may discard all 5) | 5 |
| 6       | 2 | 3 |
| 7–8     | 3 | 2 |

### Why the keep rule exists — stock after the deal is `48 − 5 × players`
| Players | Stock | Discards the stock can cover, per player |
|--------:|:-----:|:---------------------------------------:|
| 4 | 28 | 7 (all 5, freely) |
| 5 | 23 | ~4 |
| 6 | 18 | exactly 3 → matches the keep-2 rule |
| 7 | 13 | ~1–2 |
| 8 | 8  | 1 |

At 7–8 players the keep-3 rule still asks for more cards than the stock holds,
so the **dry-stock rule** below governs what late seats actually get.

### Dry stock
When the stock runs out mid-draw, **reshuffle the discards** into a fresh stock
and keep drawing. *(Sub-detail — §8: does this include discards already made this
round, allowing a player to redraw a card they just pitched?)*

### OPEN draw details — see §8
Stand-pat allowed? Draw order? Do opponents see how many you drew? May you
discard counters?

---

## 6. Meld

After the draw, players reveal scoring combinations from their final 5-card hand.
Melded cards **stay in hand** and are played normally during the tricks.

| Meld | Value |
|------|:-----:|
| Marriage (K + Q, same suit) — **in trump** | 4 |
| Marriage (K + Q, same suit) — **non-trump** | 2 |
| 9 of trump | 1 |
| Pinochle (Q♠ + J♦) | 4 |
| Four Aces — one of each suit | 10 |
| Four Kings — one of each suit | 8 |
| Four Queens — one of each suit | 6 |
| Four Jacks — one of each suit | 4 |

**Meld counts toward your bid / target.** A team on a bid of 12 that shows 5 in
meld needs only 7 more in captured counters to make it.

### OPEN meld details — see §8
Trump-run (A-10-K-Q-J) meld? Doubles (double pinochle, double marriage)? May one
card fill two different melds at once? Must a side win a trick to keep its meld?
Do non-bidding players lay meld down at all (it scores 0 for them)?

---

## 7. The play

- The **winning bidder leads** the first trick. The winner of each trick leads
  the next.
- 5 tricks per hand.
- **Strict "all-beat" rules:**
  - Must **follow suit**, and if following, must **beat** the highest card of the
    led suit currently in the trick, if able.
  - **Void in the led suit → must trump**, and if trump is already in the trick,
    must play a **higher trump** (over-trump) if able.
  - Only when you can **neither follow, nor beat, nor trump** may you **sluff**
    (discard any card).

---

## 8. Scoring

**Every side must win at least one trick to keep its meld** — a side that takes
zero tricks forfeits its melded points (it still keeps any captured counters,
which for a trickless side is zero).

### Bidding side
Total for the hand = **kept meld + counters captured in tricks**.

- **Total ≥ bid → made it.** Score the full total (it may exceed the bid).
- **Total < bid → set.** **Forfeit all meld** and score **−(bid)** flat —
  captured counters do not offset the penalty. In team play the whole
  partnership takes the negative as a unit.

### Everyone else
Each non-bidding side scores **kept meld + counters captured in tricks**
(*ratified 2026-09-09 — supersedes the earlier "non-bidders score 0" rule*).
Only the bidding side can go negative.

---

## 9. Game end

| Players | Target score |
|--------:|:------------:|
| 4 | 50 |
| 6 | 150 |
| 8 | 200 |

- **OPEN:** targets for 3- and 5-player cutthroat. Is 2-player a real mode?
- **OPEN:** any "must bid it out" rule on the final hand, or does the bidding
  side simply win by being the only side that can score?

---

## 10. Open questions to resolve

### Draw
1. **Dry-stock reshuffle** — does the reshuffled pile include discards already
   made *this* round (so a player could redraw a card they just pitched), or only
   cards that were never in play this round?
2. **Stand pat** — may you discard 0?
3. **Information** — do opponents see how many cards each player drew?
4. **Discard restrictions** — may you discard counters (A/10/K)?
5. **Draw order** — bid winner first, or left-of-dealer first?

### Meld
6. Is there a trump-run (A-10-K-Q-J of trump) meld? Any doubles?
7. May one card count in two different melds simultaneously (e.g. Q♠ in both a
   marriage and a pinochle)?
8. Must a side win at least one trick to keep its meld?
9. Confirm no-trump handling: all marriages = 2, no 9-meld, no trumping in play.
10. Do non-bidding players lay meld down at all (purely cosmetic, scores 0)?

### Sides / seating
11. Cutthroat targets for 3 and 5 players; 2-player mode yes/no.

### Deal / misc
12. Cards dealt how many at a time; any card turned face-up; deal and dealer
    rotation (assumed clockwise).
13. Confirm: meld + counters **exactly equal** to the bid counts as **made**
    (≥, not >).
14. "Must bid it out" on the final hand? (§9)

---

## 11. Implementation context (not yet designed)

*Captured for later — no decisions made here yet.*

- **Identity:** `sgc-auth` (MySQL `sim_auth.sim_auth_user`), players keyed by
  `user_uuid`, display name `user_name`. Session-based; CORS restricted to
  `*.perchance.org`.
- **Database (live):** `poker_pinochle` on `localhost`, schema per
  `migrations/0001_init.sql` (unprefixed table names). App user `pp_user` /
  `Tardaed!01` has CRUD on `poker_pinochle.*` **and read-only `SELECT` on
  `sim_auth.*`** — so names/ban status can be read straight from
  `sim_auth_user` without calling sgc-auth over HTTP.
- **Serving:** `w:\` is the web root (`https://haneldev.zapto.org/`); the app is
  served from `https://haneldev.zapto.org/poker-pinochle/`.
- **Real-time:** **Pusher** carries everything live — dealing, the draw, the
  auction, trick play, score updates, **and** in-game / lobby chat. Plan on one
  presence channel per table (seat roster, play events) plus a chat channel
  (per-table, and likely a global lobby channel). A Soketi instance
  (`w:/soketi-test`, Pusher-protocol-compatible) is available as a self-hosted
  fallback if hosted Pusher limits become a problem.
- **Serving pattern:** the `sgc-core` model (PHP Origin-gate + versioned section
  files) is a possible template for delivering the client.
- **Server authority:** shuffle, deal, stock, and legal-move validation must live
  server-side; never trust the client.

### Draft persistence model (for "hands / games / partners")
- `pp_player` — profile per `user_uuid`; games/hands played, rating, record.
- `pp_game` — one match: player set, sides, player count, target, start/end,
  final scores, winner.
- `pp_team` — the chosen team roster for a game (2–4 players), so "who was on a
  team with whom, and their record together" is queryable.
- `pp_hand` — per hand: game ref, dealer, auction result (winner, bid, trump),
  meld per side, counters captured per side, made/set, running score.
- `pp_bid` — optional per-bid auction log for analytics.
