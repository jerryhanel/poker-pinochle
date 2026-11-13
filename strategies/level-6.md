# Level 6 Playbook — Poker Pinochle

A general playbook for any game of this variant. It replaces Level 5. **Read this file first; read
`CLAUDE_AS_A_PLAYER.md` for the API mechanics.** It holds no game state: IDs, scores and hand history live on the
server and in the per-hand logs, not here.

Level 5 added trump play and a stop-and-review habit. **Level 6 corrects the ace-lead table for trump
contracts, adds the "bid your own meld" rule, records the no-calculator rule, and keeps the open questions honest.**

**Lineage:** L1 rules + Count → L2 the bid is a floor → L3 control decides no-suit play → L4 both roles,
calculated draws → L5 trump contracts, power-before-dirt, review each hand → **L6 aces can be ruffed in trump
contracts, bid up to my own meld, no calculators at the table.**

**Tags:** **[V]** verified in the engine · **[O]** observed in play (with n) · **[R]** reasoned, untested ·
**[E]** my estimate by hand arithmetic, unsimulated · **[J]** a rule Jerry taught; one school of play, not doctrine.
Treat [R] and [E] as hypotheses. A number with no tag does not belong in this file.

---

## 0. Ground rules (agreed with Jerry)

- **Integrity:** I play only from my own hand and what has been shown or played. I never read the deal file,
  `State`, the DB, or another seat's cards.
- **No calculators at the table.** In the real world I have only the playbook and my head. **No scripts,
  simulations or enumerations while a hand is in progress.** Hand arithmetic is fine and is tagged [E].
  Simulation is for reviewing the playbook *between* games, and only when Jerry asks.
- **Poll once per 10 seconds.** **Explain each move before making it:** the hand, the reasoning, the rule I'm
  following or deviating from. Label every deviation.
- **Stop after each hand** for review. Don't ready up for the next one until Jerry says so.
- **Don't edit docs until Jerry says so.** Evaluate first, then write.
- **Keep a per-hand log in the scratchpad.** Write it with the Write tool (UTF-8). **Never `Add-Content` suit
  symbols** — the default encoding turns them into `?`.
- **Jerry's rules are one school of play, not doctrine.** I may develop my own, but I label deviations and why.

---

## 1. How the game works *(all [V] unless marked)*

1. **Five cards, five tricks: every card in my hand is played.** A hand is *winners*, *donors*
   (counters landing in tricks I lose) and *filler* (9 / J / Q).
2. **A counter belongs to whoever wins its trick.** Counters are A / 10 / K, 1 point each, 24 in the deck.
3. **The bid is a floor, not a ceiling.** The bidder scores the *full* total if it reaches the bid; if not,
   `−bid`, meld lost. Exactly equal counts as made. Other sides keep their meld + counters, so
   *every counter is worth +1 to whoever captures it, whoever holds the bid.*
4. **Winning the auction buys only the first lead** (and the trump choice). Control is then won trick by trick.
5. **Meld is public, conditional money.** It names exact cards and banks only if that side wins ≥1 trick.
   **A bid ≤ the bidder's own meld can only be set by a five-trick shutout.**
6. **Replacement cards:** 1⁄6 ace, 1⁄6 ten, 1⁄6 king, 1⁄2 filler.
7. **No suit:** no ruffs; an ace is beaten only by an identical ace played earlier (ties go to the earlier card).
   **Trump suit:** a player void in the led suit **must** trump, even when they can't beat what's down, and must
   over-trump if able. A player holding the led suit must follow it, and must beat if able.
8. **I can't duck.** If I can beat the trick I must. `legalPlays` from the server is exact.
9. **Draws are simultaneous and draw sizes are hidden.** Meld, the contract, leads and sluffs are public.
   The `meld` phase never shows; it passes straight to `play`.
10. **Targets:** 50 at 2 and 4 players, 150 at 6, 200 at 8, `25 × players` in other cutthroat games.
11. **Dealer bids last. If everyone passes, the dealer is stuck at the minimum.** Bidding that minimum
    accomplishes nothing extra. **[O]**
12. **Dealer alternates each hand in a 2-player game** — verify `dealerSeat` in the snapshot.

---

## 2. Control

The leader controls the trick: whatever they lead is power for that trick, and the follower must beat if able.

### Lead ladder: chance a led card loses control **[E]**
Assumes I hold none of the higher cards of that suit and none have been played, in a 2-player hand
(his 5 cards from 43 unseen), `k` higher cards unaccounted for. Risk ≈ `1 − C(43−k,5)/C(43,5)`.
His draw lets him see extra cards, so the real risk is somewhat higher.

| Lead | k | Loses control **in a no-suit contract** |
|:-:|:-:|:-:|
| Ace | 0 | **0%** |
| Ten | 2 | ~22% |
| King | 4 | ~40% |
| Queen | 6 | ~55% |
| Jack | 8 | ~66% |
| Nine | 10 | ~75% |

### **New in L6 — the table above is for NO SUIT only.**
**In a trump contract a side-suit card can also be ruffed.** Risk of a ruff on a side-suit lead ≈
`P(he is void in that suit) × P(he holds a trump)`.
- Hand-calculated [E]: with 5 cards ≈ 21% void × 83% = **~17%**; with 4 cards left ≈ **~22%**.
  Observed [O], n = 2: one such ace lead held, one was ruffed.
- So a side-suit **ace in a trump contract is not "0%"** — it is roughly the ruff risk.
- **The trump suit itself has no ruff.** Trump leads follow the ladder rows with `k` = higher *trumps* unaccounted
  (e.g. a trump king with A, A, T of trump outstanding → k = 3 → safer than a trump queen with k = 4).
- Every card I hold or have seen lowers `k`. A **proven void** in a suit makes that suit safe to lead — and,
  if it is trump, makes every trump I hold a master (§7).
- More opponents make every row worse.
- **Control decays with the tricks left [O][R].** Cash the best leads first, gamble after.

---

## 3. The auction: value both roles

**Before bidding I answer four questions.**
1. **If I win at `b`:** what is my chain? `Sure points` = aces + kept meld; `gambles` = tens, then kings.
   In a trump contract a side ace is a *near*-sure lead (§2), not a certain one.
2. **If I'm overbid:** what is this hand worth as a defender (counters I can still capture, donors I'd hold)?
3. **Compare:** `P(make)·E[total] − (1−P(make))·b` against `E[defender score] + value of the lead`. All inputs are my estimates. **[E]**
4. **Where am I in the order?** A later bidder sees my number and needs only one more. **[R]**

### Bid only what the chain covers
1. Bid the minimum, or one above the high bid, only if the bid is covered by sure points, or by sure
   points plus **one** ten-gamble. **Never rest the bid on a king.**
2. **Never bid higher than needed to win the auction** — *except the meld rule next.*
3. **Bid my own meld [R, n = 1].** If I'm the **first** bidder (a later bidder needs only +1) and my
   **own meld alone covers a higher bid**, I may bid up to that meld. Set risk is the five-trick shutout only [V],
   and it makes an overbid cost more. Observed once [O]: a meld-5 hand bid 5 as first bidder, the opponent
   passed, and the bid was made. *The test is coverage, not par.* Par at 2 players is only ~3.5–4 [E]; a bid of 5
   is high for the table but can be covered by cards.
4. If it isn't covered, **pass**.
5. Once the bid is banked (total ≥ bid and ≥1 trick), further gambles are upside only.
6. **Dealer:** if everyone passed I'm stuck at the minimum for free, so pass. If someone bid, raise only if my chain covers the higher number.
7. **A bid is mostly noise.** Only a jump means something: it points to meld or several aces. *(A jump from me
   signals meld/aces too — accepted cost, since a hand that wins on cards doesn't need surprise.)*

### Reference totals **[E]**

| Table | Min bid | Typical total for a side |
|---|:-:|:-:|
| 2 players | 2 | ~3.5–4 |
| 3 players, cutthroat | 3 | ~3.5–4 |
| 4 players, teams | 4 | ~7 |
| 5 players, cutthroat | 5 | ~3.5–4 (min is above par) |
| 6 players, teams | 6 | ~10.5 |

Teams are untested. Never raise a partner's winning bid.

---

## 4. Declaring the contract

1. **Royal marriage (K+Q = 4)** → declare that suit, if I have support in it. *(Observed once [O]: marriage + a 9 in the suit = meld 5, made.)*
2. **Longest suit with an ace or ten** → declare it. *(Observed once [O]: A, T, Q, 9 of one suit as trump; the opponent went void after one lead of it; four tricks.)*
3. **No suit** when strength is spread or the meld is suit-independent (pinochle, four-of-a-kinds). Marriages shrink to 2 and the 9-meld vanishes. **[V]**

A declaration is public and happens before the draw. The defender decides their draw knowing it.

**Trump vs. no-suit, one ace and a long suit [R]:** in no suit only an ace is a sure lead; in trump, a long suit
with its top cards is a chain of sure leads, **but side aces cost a ~17–22% ruff risk [E]** that no-suit does not have.

---

## 5. The draw: calculate, don't look up

Most later plays are forced, so the draw is the largest lever I have.

For every card ask: *what is it worth to keep versus a random replacement?* A random replacement is a counter
with probability 1⁄2 and an ace with probability 1⁄6.

- **A counter is worth keeping if `P(I win its trick) > 1⁄2`.** Net value of a kept counter ≈ `2·P(win) − 1`. **[R]**
- **An ace** has the highest `P(win)` when I can lead it. **A ten or king** needs a `P(win)` from the ladder or a proven void.
- **Filler** (9 / J / Q) is worth keeping as a **defender** (it is what I sluff so counters aren't donated). As a **declarer** it is dead weight unless it's a meld half or a trump.
- **Trump contracts:** keep every trump card I hold (they are the chain), trump 9s (meld) and K+Q pairs.
- **Stand pat when every card is meld, trump, or a counter-winner.** Observed once [O], n = 1: all five cards qualified and the hand held.

### By role
- **Declarer:** keep aces, tens backed by an ace, trump cards and meld halves. Replace dead filler. My bid must stand on counters I hold.
- **Defender vs. a no-suit bid:** "starving" (pitching every counter) helps only when his hand does *not* cover the bid, and then only ~½ point per counter. It cannot stop a bidder whose own cards cover the bid. **[R][O]**
- **Defender holding two counters and three filler:** standing pat is reasonable [R, n = 1]. The filler covers the sluffs on tricks I can't win, so my counters aren't dropped into them. A borderline ten (P(win) ≈ 0.5) is the only card in doubt.

### Open draw question, one data point **[O]**
*Lone off-suit king when the bid is already covered: keep or pitch?* One observed case: pitched it
(est. P(win) ~0.5, replacement ~+0.15 [R]) and drew filler that later cost a counter. Can't be judged from one hand. No rule yet.

---

## 6. Meld

1. **Compute their floor:** `Need = bid − their meld` counters.
2. **`bid ≤ their meld`:** only a five-trick shutout sets them. Go to damage control.
3. **`Need` large (≥ 5 at 4 players, or any bid resting on captures):** contest. Win control early and lead power.
4. **My own meld banks only with ≥1 trick.**
5. **Read names.** Each card has two copies. An empty meld proves the hand holds no K+Q pair, no Q♠+J♦ together, no four-of-a-kind, and **no 9 of the contract suit** (each copy scores). **[V]** *(Example: under a clubs contract an empty meld means no 9♣, so every club they could hold is ≥ J.)*
6. **Weak meld with a high bid** means the strength is in aces/tens. **An empty meld with a bid** means the bid rests entirely on captured counters.

---

## 7. Playing the five tricks

**Before every play:** count my legal choices (one = forced); list what is **proven** about the other hand
(voids, "nothing higher"); decide whether my bid is banked (then the next lead is upside only); then apply the tie-breaks below.

**Leading**
1. **Cash aces first.** In no suit they can't lose; **in a trump contract they can be ruffed (§2)**, so ask
   *can he be void in this suit and still hold trump?* before leading a side ace. If the bid is banked, cash anyway
   — the downside is one counter.
2. Then the safest remaining lead by the ladder in §2, with `k` adjusted for everything seen.
3. **Void inference first:** a sluff on the last lead proves a void, so a high card in that suit is now safe.
4. Before my bid is banked, never stake it on a king.
5. Avoid leading below a king unless the alternative is worse. **[J][E]**
6. **In a trump contract, once the opponent is proven void in trump, every trump I hold is a master** [V]
   (he can't follow, and a forced trump is the only way to beat it). **[O]** n = 1: after the opponent sluffed on a
   trump lead, my remaining trump was unbeatable and captured an ace.
7. **A trump lead that the opponent can answer with any trump hands him the trick.** Don't lead a low trump just
   to "draw trump" when I hold side aces to cash: the engine's `preserve_power` hint does exactly that and I have
   declined it three times with good results **[O]**, n = 3.

**Following**
1. Win as cheaply as possible (lowest legal winner). Winning gives me the lead, so know what I'll lead next.
2. **When forced to trump and every trump wins, play the cheapest [O], n = 1.** Open question: a
   counter-trump (K or T) played now is a *certain* capture, whereas held back it can be beaten (a trump king with
   A, A, T of trump outstanding ≈ 15% [E]). Level 6 has no rule weighing this. **Log it every time.**
3. When I can't win, sluff **non-counters first**; counters get played eventually.
4. When two sluffs look equal, **keep the card that could win a later trick**. A filler that can win cheaply
   (a J that beats a 9 lead) is worth more than a 9, which can never win as a follower.

**Tie-breaks when two plays are equal on my analysis**
1. **Play the power card before the dirt card [J].** It protects me if I've miscounted.
2. Prefer the play that keeps more choices for later.
3. Prefer the play that doesn't let the opponent choose which of his cards to give up.

**The engine's `suggestedPlay` is a baseline, not an authority.** Use it as a sanity check and log disagreements.
In the disagreements logged so far, my ladder / ace-first reasoning did better than the hint, and on free sluffs the
two have usually agreed. Small sample **[O]**.

**Reading:** a sluff = void in the led suit (and in trump if one exists); a low follow = nothing higher in that suit.
**A forced follow can strip counters [O]:** an opponent forced to follow my ace with a king gave up a counter.
**A ruff proves a void** [V] and costs the ruffer a trump (a trump ten on a heart ace proves no hearts).

---

## 8. Match management

- Every point a rival scores brings the end closer, so limiting a total matters even when I can't set it.
- Ahead: take the free minimum, pass uncovered bids. Behind late: bid covered chains only, not hope.
- When a rival is a hand from the target, defend for counters on every hand.

---

## 9. Using evidence from the table

- **Play a sound baseline against everyone.** Don't build strategy around one person's habits.
- A habit seen **fewer than ~10 times** is a hypothesis. It may shift a *close* decision; it never overrides a covered or uncovered chain.
- **Evidence that always counts, because it is forced by the rules:** meld names cards; a sluff proves a void; a ruff proves a void; a low follow proves "nothing higher"; a played card is gone.
- **Evidence that is only a tendency (record it, don't rely on it):** bid sizes, lead order, which counters get pitched. Keep such notes in the per-hand log, not in this playbook.
- **A declaration is also information, a tendency, n = 1 [R]:** a no-suit contract is typically the choice of a hand with
  spread power, i.e. aces. When a declarer picks no suit, treat my tens and kings as riskier than the §2 ladder says.
  Observed once [O]: a ten I kept at the neutral ~22–28% risk lost to the declarer's ace. Not yet a rule.

---

## 10. Measuring myself

### Decision log (one line per bid / draw / non-forced play)
`hand | decision | role considered | my probability or EV estimate | outcome | miss or not`

### Regret check (end of each hand)
1. What was the best alternative I had at each real decision, and what would it have scored?
2. Was the loss from the **decision** or from the **cards**? Only decisions are mine to fix.
3. One sentence: what would I change, and is it supported by more than this hand?

*(Results, scorecards and per-game tallies belong in the per-hand logs, not here.)*

---

## 11. Confidence ledger and priorities

| Claim | Status | Test |
|---|---|---|
| Lead-risk table (§2), no suit | Hand-calculated | Simulate deals: how often each led rank keeps control |
| **Side-suit ace ruff risk ≈ 17–22% in trump (§2)** | Hand-calculated; n = 2 [O] (1 held, 1 ruffed) | Simulate; play more trump hands |
| Coverage test beats Count thresholds | Reasoned | Make/set rates over 20+ hands |
| **Bid up to my own meld as first bidder (§3.3)** | Reasoned; n = 1 [O] (opponent passed, bid made) | More hands; see whether opponents overbid |
| Both-role bid comparison (§3) | Reasoned, one worked example | Simulate declarer vs. defender EV by hand type |
| Keep a counter if `P(win) > 1⁄2` (§5) | Reasoned; one unresolved case | Simulate draw policies |
| Lone off-suit king: keep or pitch | One case, undecided | Same simulation |
| Defender starve value (~½ point per counter) | Reasoned, one case | Same simulation |
| Defender with 2 counters + 3 filler: stand pat | n = 1 [O]; result was cards, not a decision miss | Compare with pitching the borderline ten |
| Trump contract: long suit + top trumps beats no suit | n = 2 [O] | Play more; simulate |
| Cheapest trump vs. bank the K (§7 Following 2) | n = 1 [O], undecided | Log every instance |
| Ladder choices beat the engine's `preserve_power` | n = 3 [O] | More plays |
| Power before dirt when plays are equal | [J], logic checked | Cost is zero |
| Control decays with tricks left (§2) | n = 2 | Simulate how often a late lead converts |
| **A no-suit declaration signals aces, so my tens/kings are riskier than the §2 ladder (§9)** | Reasoned; n = 1 [O] | More no-suit hands; simulate P(ace given declarer chose no suit) |
| Teams rules | Untested | Play them |

**Priority 1:** keep the ground rules in §0 — especially **no calculators at the table**.
**Priority 2:** run the decision log and regret check every hand, and stop after each hand for review.
**Priority 3 (between games, only if Jerry asks):** simulate with a **copy** of the engine with no database
writes, to turn the [E] and [R] rows into numbers.
