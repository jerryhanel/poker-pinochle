# Level 1 Strategy — "Careful Beginner"

**Written:** 2026-11-06 · **Status:** first draft, hand-calculated, **not yet simulated**.
Numbers marked *(est.)* are my own arithmetic and should be tuned from real results.
Rules below were checked against `engine/Trick.php`, `Score.php`, `Meld.php`,
`Auction.php`, `State.php` — where the engine and `DESIGN.md` differ, **the engine wins**.

**Goal:** score the most points over the game, and set the opponent when it is cheap
to do so. Level 1 = play safe, count everything, never gamble on a hand I can't price.

**Integrity clause:** I play only from my own hand plus what has been shown or played
on the table. I never read the deal file, `State`, the DB, or another seat's hand.

---

## 0. Facts that drive every decision

**Scoring (engine):**
- Bidding side: `meld(kept) + counters`. Total ≥ bid → score the **full total**. Total < bid → **−bid flat**, meld lost, counters don't help.
- Everyone else: keeps `meld + counters` too. So *every* counter I capture is worth +1 to me whether or not I hold the bid.
- A side with **zero tricks loses its meld** (bidders and defenders alike).
- Exactly equal to the bid counts as made.

**Why being set hurts so much:** I drop from "about +7" to "−bid", and the other side still
banks its own counters and meld. A set is a ~10–12 point swing at 4 players.

**Rough numbers** *(est.)*:

| Table | Min bid | Typical total for one side | What that means |
|---|:-:|:-:|---|
| 4 players, teams | 4 | ~7 (5 counters + ~2 meld) | Minimum bid is easy; 5–6 is fair with a decent hand; 8+ needs real help |
| 6 players, teams | 6 | ~10.5 (7.5 + ~3) | Same shape: min bid comfortable |
| 3 players, cutthroat | 3 | ~3.5–4 | Min bid is only a slight favorite — **I need an edge** |
| 5 players, cutthroat | 5 | ~3.5–4 | Min bid is **above** typical. Pass unless strong. Being stuck as dealer hurts |
| 2 players | 2 | ~3.5–4 | Min bid is safe; 3 with an ace is fine |

- A trick holds about `players ÷ 2` counters (≈2 at 4 players). Five tricks per hand.
- Before the draw: ~13% of hands hold a K+Q marriage; ~19% hold 2+ aces; a given opponent is void in a given suit ~22% of the time.
- Counters are only A / 10 / K (1 point each). 9, J, Q are worth nothing to capture.

---

## 1. Phase: AUCTION (and declaring the suit)

### Default = PASS
Passing is safe. The only forced bid is the dealer's, and that is the minimum.

### Dealer rule
- **If everyone before me passed, I'm stuck at the minimum for free → don't bid.** (Bidding the minimum accomplishes nothing extra.)
- If someone has bid, I outbid only if my Count (below) clears their bid + 1.

### Hand Count (before the draw)
```
Count = meld I hold now (in the suit I'd declare)  +  2 per Ace  +  1 per Ten
```
Kings are a bonus I don't count. Pinochle (Q♠+J♦) = 4. Four aces = 10, etc.

| Situation | Open at the minimum when… | Never go above… |
|---|---|---|
| **Teams** (partner pools with me) | Count ≥ n (n = players) | Count (and Level-1 cap of **8**, unless my *meld alone* covers the bid) |
| **Cutthroat / no partner to lean on** | Count ≥ n + 1 | Count − 1 |

Check against the examples: a K♥Q♥ marriage = Count 4 → open at 4 in a 4-player team game.
**Two aces and nothing else = Count 4 → bid 3 in a 3-player game, hoping for "no suit".**

### Rules of thumb
1. **Partner already holds the high bid → pass.** Raising my own team only raises my risk.
2. **An opponent holds the high bid:** raise only if Count clears it by at least 1 *and* I'd still be comfortable at that number.
3. **Read their bids.** A minimum bid = ordinary strength. A big jump (+3 or more) = real meld (a four-of-a-kind or marriages) or several aces. Respect it; a jump bid from the other side is usually *not* a bluff worth calling at Level 1.
4. **Meld bids are the safest bids.** A bid ≤ my held meld only needs **one trick** (to keep the meld). Plan the first lead around winning one.
5. If an opponent bids exactly on meld, they may have almost no counters. That is when a defender can actually set them (see §4).
6. Long-shot draws (e.g., chasing the 4th ace, ~9%) are **not** a reason to bid.

### Declaring the suit (happens before the draw, so declare what I'll draw toward)
In order of preference:
1. **The suit of my best meld** (royal marriage = 4; two marriages → the one backed by trump A/10).
2. **My longest suit that includes an Ace or Ten** (3+ cards in trump with A/10 dominates; ~22% of opponents are void in any single suit and will be forced to trump me if they hold trump).
3. **"No suit"** when my strength is spread across suits (2+ aces/tens in different suits) and I hold no marriage:
   - No one can trump my aces; an ace I lead cannot lose (identical cards tie → the **earlier play wins**).
   - Cost: marriages are worth only 2 and there is no 9-meld.

---

## 1b. Phase: DRAW (sits between declare and meld)

The draw is **simultaneous**: everyone discards first, then replacements come from one shared pool. I cannot react to what others pitch, and the table only sees *who has* discarded, not how many.

**Keep:** both halves of any meld; any Ace or Ten; trump cards; Kings that pair with a Queen or are trump; three of a kind-in-different-suits toward a four-of-a-kind.
**Discard:** off-suit 9s, Js, unpaired Qs, unpaired off-suit Ks.
- A new card is a counter ~50% of the time, so a lone Ace/Ten is better than the average replacement — **keep it.**
- Stand pat (discard 0) is legal. Do it when my hand already has meld + counters.
- Never discard so much that I can't follow suit in my declared trump.

---

## 2. Phase: MELD (reveal, then read)

### My own meld
1. Total my side's meld. `Need = bid − team meld` = counters I still have to capture (**plus ≥1 trick**, or the meld is forfeited).
2. If Need ≤ 2, **stop taking risks**: win one trick, don't burn power cards, don't feed the defense.
3. Meld cards stay in my hand. Don't spend a marriage's K or Q casually if I need them to win tricks later; but remember melded points are already banked once I've won a trick.

### Watching everyone else's meld (this is the information phase)
Every meld **names cards** that remain in that hand until played:
- A marriage = they hold that K and that Q.
- Four aces/kings/queens/jacks = one of each suit.
- Pinochle = Q♠ and J♦.
- 9s in the trump suit.

Each card has **two copies**. So:
- If I hold a K♠ and an opponent shows K♠+Q♠, both K♠s are accounted for → no third king of that suit exists. My own K♠ is *not* safe from their Aces/10s though.
- **My King is safe** in a suit when both Aces and both Tens of that suit are accounted for (mine, partner's, shown in meld, or already played).
- Absence of meld is information too: a player who bid and showed little meld is probably holding Aces/Tens (power cards that don't form meld).
- A shown marriage in **non-trump** suit means K/Q are mid-value for them; their trump holdings are the thing to count.

### Keep a tracker (update after meld and after every trick)
- 24-card grid: every A/10/K/Q/J/9 × suit, two copies each: **in my hand / seen / unseen**.
- Counters played so far, counters captured per side.
- Known voids per player (see §3).
- Trump remaining: of the 12 trump cards, how many are still out?

---

## 3. Phase: PLAY (5 tricks)

### What the legal-move ladder tells me (verified in `Trick.php`)
| What a player does | What I know |
|---|---|
| **Follows the led suit, but plays lower than the best led-suit card on the table** | They hold **nothing higher** in that suit |
| **Plays a trump on a non-trump lead** | **Void in the led suit** (may hold more trump) |
| **Plays a card that is neither the led suit nor trump** (a sluff) | **Void in the led suit AND void in all trump.** They can no longer win a trick except by leading an unbeaten card |
| Under **no suit**: plays off-suit | Void in the led suit (nothing else follows — no trump exists) |
| **Plays a low trump even though a higher trump is already down** | Forced: they hold trump but nothing bigger |
| Over-plays when they could have played lower | They aren't being careful, or they want the lead |

**Also:**
- **I can't duck.** If I can beat the led suit, I must. This applies *even over my partner*. Don't hold lone high cards expecting to hide them.
- If I'm void in the led suit and hold any trump, I **must** trump (even if I can't beat what's down).
- Equal cards tie → the earlier one wins.

### Leading (bidder or anyone who wins a trick)
1. **Lead a card that cannot be beaten.** Test: (a) no higher unplayed card exists in that suit, and (b) no opponent is a *known void with trump left* (they'd be forced to trump it).
2. Holding the **trump Ace**: lead it. In trump contracts it's the one lead that is never exposed to a ruff.
3. Holding **3+ trump including A/10**: lead trump to drain opponents' trump (strict rules force them to spend high ones), then cash side-suit aces.
4. **No-suit contract:** lead each Ace; when I win, lead the next. Then Tens once both Aces of that suit are gone.
5. Don't lead a **side-suit Ace into a possible ruff** early in a trump contract unless I have no better lead; do it on trick 1–2 before suits get thin.
6. If I hold 2+ of a suit without the Ace, lead the low one and keep the high ones for later.
7. If I have nothing: lead my lowest card (the engine's own heuristic calls this *hand_control*).

### Following
- **Partner is already winning the trick and I can't beat it** (or I'm legally free): put a counter on it — "feed" a non-trump counter; otherwise play my lowest non-counter.
- **Opponent is winning and I can beat it:** win as **cheaply** as possible (lowest legal winner).
- **Opponent is winning and I can't:** sluff a **non-counter**. If every legal card is a counter, give up the cheapest.
- Keep a **low trump** in hand as a safe follower if trump is led.

### As the BIDDER
- Keep running `Need`. After each trick: counters captured so far, counters still unplayed, tricks left. If `captured + counters-I-can-still-capture < Need`, I'm set no matter what — minimise damage (nothing to do) and bank counters. If `captured ≥ Need` and I've won ≥1 trick, I made it: stop contesting, play safe, and add counters.
- Win trick #1 or #2 if my bid depends on meld.

### As a DEFENDER (this is how I set them)
- Compute their `Need` the moment meld is shown. If it is **≤ 3** at a 4-player table, I almost can't set them. Switch to maximising **my own counters.**
- If their `Need` is **≥ 5** counters, or they'd need nearly every trick, contest hard: win tricks with counters, trump their ace leads when I'm void *and* hold trump (I'm forced to anyway).
- A defender void in a suit with trump is dangerous to a side-suit Ace lead — lead my own power cards to get it.
- **Shut-out chance:** if the bidder's bid rests on meld, taking all five tricks forfeits it. Realistic only if the bidder led weak.

### Reading as I go
- After every trick, update voids. A player with a confirmed trump-void **can't win** with trump; lead into them freely.
- When the unplayed high cards in a suit are all in my hand or partner's, my low cards in that suit become winners.
- Count Aces: 8 total (2 per suit). After both Aces of a suit are out, the Tens are masters.

---

## 4. Match management (4-player: first to 50)

- A normal hand moves a side about +7. Expect ~7 hands.
- **I'm ahead (>15 up):** pass marginal bids, keep the dealer-stuck bid, aim to not get set.
- **I'm behind late:** bid on meld-backed Counts (a guaranteed 4–8 beats +7 average). Don't bid on hope.
- **They're within ~8 of the target:** they can end it in one hand. Defend for counters on every hand (counters are mine regardless of who bid).
- Never bid because I'm "due". Each hand is independent.

---

## 5. Recording and review

After every hand, jot: my Count, the bid, the suit declared, meld each side, counters each side, made/set, and **one thing I misjudged**. I'll use that to calibrate the numbers in §0 and the Count thresholds in §1.

## 6. Questions Level 1 doesn't answer yet (Level 2 topics)
- Verify the *(est.)* numbers with a simulation (random legal play is enough to calibrate par and make-rate by bid).
- Partner signalling via bid size and first lead.
- Bidding to *deny* (taking the trump choice away) vs bidding to *score*.
- Deliberate set attempts: when is the swing (their −bid + my counters) worth giving up a safe trick?
- Using the stock (what was discarded) to estimate what's unseen.
- Endgame handling at 40+ with a specific number needed.
