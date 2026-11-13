# Level 7 Playbook — Poker Pinochle

A general playbook for any game of this variant. It replaces Level 6. **Read this file first; read
`CLAUDE_AS_A_PLAYER.md` for the API mechanics.** It holds no game state: IDs, scores and hand history live on the
server and in the per-hand logs, not here.

**Lineage:** L1 rules + Count → L2 the bid is a floor → L3 control decides no-suit play → L4 both roles,
calculated draws → L5 trump contracts, power-before-dirt, review each hand → L6 aces can be ruffed in trump
contracts, bid up to my own meld, no calculators → **L7 a bid can be a denial; surplus filler is dead weight even
for a defender; count down the opponent's hand from his meld.**

## What Level 7 adds (everything else is Level 6, unchanged)
1. **A bid can be a denial (§3).** Overbidding to break a hand, then redrawing most of mine, is a real tactic. I need to read it when it is used on me, and I may use it.
2. **Cover is counted, not assumed (§5).** Filler is worth keeping as a defender only up to the number of counters I must protect. The rest is surplus and should be replaced.
3. **Count down the opponent's hand (§6, §7).** Meld names exact cards that stay in his hand. After a few plays his last cards can be fully known, so the final tricks can be planned.
4. **A minimum bid on a thin chain invites a cheap overbid (§3).**

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
   The `meld` phase never shows; it passes straight to `play`. **The declarer's suit is committed *before* the draw,
   so it reflects his original five cards, not his final hand.**
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

### The table above is for NO SUIT only.
**In a trump contract a side-suit card can also be ruffed.** Risk of a ruff on a side-suit lead ≈
`P(he is void in that suit) × P(he holds a trump)`.
- Hand-calculated [E]: with 5 cards ≈ 21% void × 83% = **~17%**; with 4 cards left ≈ **~22%**.
  Observed [O], n = 2: one such ace lead held, one was ruffed.
- So a side-suit **ace in a trump contract is not "0%"** — it is roughly the ruff risk.
- **The trump suit itself has no ruff.** Trump leads follow the ladder rows with `k` = higher *trumps* unaccounted
  (e.g. a trump king with A, A, T of trump outstanding → k = 3 → safer than a trump queen with k = 4).
- Every card I hold or have seen lowers `k`. A **proven void** in a suit makes that suit safe to lead — and,
  if it is trump, makes every trump I hold a master (§7).
- **In a 2-player hand, a ruff of his lead always wins:** he has already played, so nobody is left to over-trump. [V]
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
2. **Never bid higher than needed to win the auction** — *except the meld rule below.*
3. **Bid my own meld [R, n = 1].** If I'm the **first** bidder (a later bidder needs only +1) and my
   **own meld alone covers a higher bid**, I may bid up to that meld. Set risk is the five-trick shutout only [V],
   and it makes an overbid cost more. Observed once [O]: a meld-5 hand bid 5 as first bidder, the opponent
   passed, and the bid was made. *The test is coverage, not par.* Par at 2 players is only ~3.5–4 [E].
4. If it isn't covered, **pass**.
5. Once the bid is banked (total ≥ bid and ≥1 trick), further gambles are upside only.
6. **Dealer:** if everyone passed I'm stuck at the minimum for free, so pass. If someone bid, raise only if my chain covers the higher number.
7. **A bid is mostly noise.** Only a jump means something: it points to meld or several aces. *(A jump from me
   signals meld/aces too — accepted cost, since a hand that wins on cards doesn't need surprise.)*

### **New in L7 — the denial bid**
8. **A bid does not have to rest on the hand in front of me.** An opponent may overbid to take the contract away from
   a hand he expects to be strong, then redraw most of his own cards. He accepts a risk of being set (`−bid`) to
   remove my declarer chances. **Observed once [O], n = 1, used on me [J].**
9. **Reading it when it is used on me [R]:** an overbid whose chain isn't covered by what I can see probably rests on
   the draw. His suit was committed before that draw (§1.9), so it describes his *original* cards, not his final hand.
   His bid is less covered than it looks, and the chance of setting him is higher than the usual numbers say.
10. **Using it myself [R, untested]:** consider it only if (a) beating the bid costs little (the minimum, or +1),
    (b) my own hand would likely make the bid after a heavy redraw, and (c) the set cost `−b` is small against the
    declarer value I'm removing. Otherwise a denial bid is just a bad bid.
11. **A minimum bid on a thin chain offers the opponent a cheap denial bid [R, n = 1].** Being overbid cost me
    nothing, so it is not a reason to pass a covered chain. It is a reason to expect the overbid and plan the draw
    as a defender before I bid.

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
- **Trump contracts:** keep every trump card I hold (they are the chain), trump 9s (meld) and K+Q pairs.
- **Stand pat when every card is meld, trump, or a counter-winner.** Observed once [O], n = 1: all five cards qualified and the hand held.

### **New in L7 — count the cover I need**
- **Filler** (9 / J / Q) is worth keeping as a **defender** only as **sluff cover for the counters I must protect**.
  Roughly one filler per counter I hold [R]. **Anything beyond that is surplus: replace it,** exactly as a declarer
  would. A hand of mostly nines can't win tricks, so it needs aces and trumps more than it needs cover. **[J][R, n = 1]**
- **When the numbers are a wash [R]:** a break-even draw has more upside than a stand-pat when my hand has almost
  no winners, because the cards I'd throw away can't win anything. Prefer the draw, and keep trump, meld halves and
  my counter-protection.
- *Why this changed:* in one hand I kept three nines as "cover" for a single counter and calculated the draw at
  about zero (my estimate [E]). The stronger player's view, which I accept as a likely miss, was that I should have
  burned the worthless nines and drawn for aces and trumps. Evidence is n = 1, so it stays [R].

### By role
- **Declarer:** keep aces, tens backed by an ace, trump cards and meld halves. Replace dead filler. My bid must stand on counters I hold.
- **Defender vs. a no-suit bid:** "starving" (pitching every counter) helps only when his hand does *not* cover the bid, and then only ~½ point per counter. It cannot stop a bidder whose own cards cover the bid. **[R][O]**
- **Defender holding a trump nine:** it is worth 1 meld (if I win a trick) and a ruff tool (§2). Keep it.

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

### **New in L7 — meld names cards that stay in his hand**
7. **Meld cards are not played away.** A marriage means *both* cards sit in his hand until he plays them [V].
   Write his hand as `five cards = meld cards + cards he has played + unknown`, and cross a card off each trick.
8. **Count down every trick.** When the unknown count reaches 0, his remaining cards are **fully known** and his
   last leads are forced. Observed once [O], n = 1: after a marriage and three plays, his last two cards were
   exactly the marriage pair, so the final two tricks could be planned with certainty.
9. **Use it to place my own cards.** If my ten beats both cards of his marriage, then a forced lead of either one
   hands me the trick, and I should keep that ten until he must lead it. **[O]** n = 1.
10. **A draw that was heavy (a denial bid, §3.8) leaves few unknown cards:** the meld pair is often most of his hand.

---

## 7. Playing the five tricks

**Before every play:** count my legal choices (one = forced); list what is **proven** about the other hand
(voids, "nothing higher", **his meld cards still unplayed**); decide whether my bid is banked (then the next lead
is upside only); then apply the tie-breaks below.

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
8. **When I win a trick late and he has a known last card, lead a suit he can't follow.** In a 2-player hand with
   no trump left, that lead can't lose. **[V]**

**Following**
1. Win as cheaply as possible (lowest legal winner). Winning gives me the lead, so know what I'll lead next.
2. **When forced to trump and every trump wins, play the cheapest [O], n = 1.** Open question: a
   counter-trump (K or T) played now is a *certain* capture, whereas held back it can be beaten (a trump king with
   A, A, T of trump outstanding ≈ 15% [E]). Level 7 has no rule weighing this. **Log it every time.**
3. When I can't win, sluff **non-counters first**; counters get played eventually.
4. When two sluffs look equal, **keep the card that could win a later trick**. A filler that can win cheaply
   (a J that beats a 9 lead) is worth more than a 9, which can never win as a follower. **Keep a counter that beats
   his known unplayed meld cards** (§6.9).

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
**His played cards plus his meld cards are his hand (§6.7).**

---

## 8. Match management

- Every point a rival scores brings the end closer, so limiting a total matters even when I can't set it.
- Ahead: take the free minimum, pass uncovered bids. Behind late: bid covered chains only, not hope.
- When a rival is a hand from the target, defend for counters on every hand.
- When the score is close, a denial bid (§3.8) costs `−b` if it fails; weigh that against the lead it takes away.

---

## 9. Using evidence from the table

- **Play a sound baseline against everyone.** Don't build strategy around one person's habits.
- A habit seen **fewer than ~10 times** is a hypothesis. It may shift a *close* decision; it never overrides a covered or uncovered chain.
- **Evidence that always counts, because it is forced by the rules:** meld names cards; a sluff proves a void; a ruff proves a void; a low follow proves "nothing higher"; a played card is gone.
- **Evidence that is only a tendency (record it, don't rely on it):** bid sizes, lead order, which counters get pitched. Keep such notes in the per-hand log, not in this playbook.
- **A declaration is also information, a tendency, n = 1 [R]:** a no-suit contract is typically the choice of a hand with
  spread power, i.e. aces. When a declarer picks no suit, treat my tens and kings as riskier than the §2 ladder says.
  Observed once [O]: a ten I kept at the neutral ~22–28% risk lost to the declarer's ace. Not yet a rule.
  *(Caution: a declaration reflects his original five cards, and a denial bid can mean he redrew most of them (§3.9).)*

---

## 10. Measuring myself

### Decision log (one line per bid / draw / non-forced play)
`hand | decision | role considered | my probability or EV estimate | outcome | miss or not`

### Regret check (end of each hand)
1. What was the best alternative I had at each real decision, and what would it have scored?
2. Was the loss from the **decision** or from the **cards**? Only decisions are mine to fix.
3. **A good outcome does not grade a decision.** Check the reasoning, not the result.
4. One sentence: what would I change, and is it supported by more than this hand?

*(Results, scorecards and per-game tallies belong in the per-hand logs, not here.)*

---

## 11. Confidence ledger and priorities

| Claim | Status | Test |
|---|---|---|
| Lead-risk table (§2), no suit | Hand-calculated | Simulate deals: how often each led rank keeps control |
| **Side-suit ace ruff risk ≈ 17–22% in trump (§2)** | Hand-calculated; n = 2 [O] (1 held, 1 ruffed) | Simulate; play more trump hands |
| Coverage test beats Count thresholds | Reasoned | Make/set rates over 20+ hands |
| Bid up to my own meld as first bidder (§3.3) | Reasoned; n = 1 [O] | More hands; see whether opponents overbid |
| **A denial bid is a real tactic (§3.8–3.10)** | Seen once, used on me [J][O]; unsimulated | Simulate the declarer EV after a heavy redraw vs. a covered hand |
| **A minimum bid on a thin chain invites a cheap overbid (§3.11)** | Reasoned; n = 1 | Track how often min bids get overbid |
| Both-role bid comparison (§3) | Reasoned, one worked example | Simulate declarer vs. defender EV by hand type |
| Keep a counter if `P(win) > 1⁄2` (§5) | Reasoned; one unresolved case | Simulate draw policies |
| **Surplus filler (beyond one per counter) should be replaced by a defender (§5)** | [J][R]; n = 1, one likely miss | Simulate draw policies for defenders with a weak hand |
| **A break-even draw beats stand-pat when the hand has no winners (§5)** | Reasoned; n = 1 | Same simulation |
| Lone off-suit king: keep or pitch | One case, undecided | Same simulation |
| Defender starve value (~½ point per counter) | Reasoned, one case | Same simulation |
| Trump contract: long suit + top trumps beats no suit | n = 2 [O] | Play more; simulate |
| Cheapest trump vs. bank the K (§7 Following 2) | n = 1 [O], undecided | Log every instance |
| Ladder choices beat the engine's `preserve_power` | n = 3 [O] | More plays |
| Power before dirt when plays are equal | [J], logic checked | Cost is zero |
| Control decays with tricks left (§2) | n = 2 | Simulate how often a late lead converts |
| A no-suit declaration signals aces, so my tens/kings are riskier than the §2 ladder (§9) | Reasoned; n = 1 [O] | More no-suit hands; simulate P(ace given declarer chose no suit) |
| **Meld cards stay in hand, so his last cards can be fully known (§6.7–6.9)** | **[V]** for the rule, **n = 1 [O]** for the payoff | Use it every hand and record when it decides the last tricks |
| Teams rules | Untested | Play them |

**Priority 1:** keep the ground rules in §0 — especially **no calculators at the table**.
**Priority 2:** run the decision log and regret check every hand, and stop after each hand for review.
**Priority 3 (between games, only if Jerry asks):** simulate with a **copy** of the engine with no database
writes, to turn the [E] and [R] rows into numbers.
