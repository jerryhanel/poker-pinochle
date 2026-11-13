# Level 5 Playbook — Poker Pinochle

Merges Level 4 with a review of hand 4 of game 1 (my first hand in a trump contract). It replaces Level 4.
**Level 4 asked me to value every decision in both roles. Level 5 adds a first look at trump play, a robustness
rule for equal plays, and a stop-and-review habit.**

**Lineage:** L1 rules + Count → L2 the bid is a floor, the draw matters → L3 control decides no-suit play →
L4 both roles, calculated draws, self-measurement → **L5 trump contracts (first data), play power first when
plays are equal, review after every hand.**

**Tags:** **[V]** verified in the engine · **[O]** observed in play · **[R]** reasoned, untested ·
**[E]** my estimate, unsimulated · **[J]** a rule Jerry taught; one school of play, not doctrine.
Treat [R] and [E] as hypotheses. A number with no tag does not belong in this file.

**Integrity:** I play only from my own hand and what has been shown or played. I never read the deal file,
`State`, the DB, or another seat's cards.

**Process (agreed with Jerry)**
- Poll once per 10 seconds. Explain each move *before* making it. Keep a per-hand log in the scratchpad.
- **Stop after each hand** for review. Don't ready up for the next one until Jerry says so.
- Don't edit the docs until Jerry says so. Evaluate first, then write.

**What changed since Level 4**
- **Tie-break: power before dirt [J]** (§7).
- **Trump contracts have a first data point** (§4, §7). Level 4 said "untested".
- **A sluff in a forced-follow spot is a proof, and a proof makes my masters unbeatable** (§7).
- **Engine hints are a baseline, not doctrine** (§7).
- The decision log and regret check now have one full hand of entries (§10).

---

## 1. How the game works *(all [V] unless marked)*

1. **Five cards, five tricks: every card in my hand is played.** A hand is *winners*, *donors*
   (counters landing in tricks I lose) and *filler* (9 / J / Q).
2. **A counter belongs to whoever wins its trick.** Counters are A / 10 / K, 1 point each, 24 in the deck.
3. **The bid is a floor, not a ceiling.** The bidder scores the *full* total if it reaches the bid; if not,
   `−bid`, meld lost. Exactly equal counts as made.
4. **Winning the auction buys only the first lead.** Control is then won trick by trick.
5. **Meld is public, conditional money.** It names exact cards and banks only if that side wins ≥1 trick.
   A bid ≤ the bidder's own meld can only be set by a five-trick shutout.
6. **Replacement cards:** 1⁄6 ace, 1⁄6 ten, 1⁄6 king, 1⁄2 filler.
7. **No suit:** no ruffs; an ace is beaten only by an identical ace played earlier (ties go to the earlier card).
   **Trump suit:** a player void in the led suit **must** trump, even when they can't beat what's down.
   A player holding the led suit must follow it, and must beat if able.
8. **I can't duck.** If I can beat the trick I must, even over a partner. `legalPlays` from the server is exact.
9. **Draws are simultaneous and draw sizes are hidden.** Meld, the contract, leads and sluffs are public.
   The `meld` phase never shows; it passes straight to `play`.
10. **Targets:** 50 at 2 and 4 players, 150 at 6, 200 at 8, `25 × players` in other cutthroat games.
11. **Dealer bids last. If everyone passes, the dealer is stuck at the minimum.** Bidding that minimum accomplishes
    nothing extra. **[O]** (hand 4)

---

## 2. Control

The leader controls the trick: whatever they lead is power for that trick, and the follower must beat if able.

### Lead ladder: chance a led card loses control **[E]**
Assumes I hold none of the higher cards of that suit and none have been played, in a 2-player hand
(his 5 cards from 43 unseen), `k` higher cards unaccounted for. Risk ≈ `1 − C(43−k,5)/C(43,5)`.
His draw lets him see extra cards, so the real risk is somewhat higher.

| Lead | k | Loses control |
|:-:|:-:|:-:|
| Ace | 0 | **0%** |
| Ten | 2 | ~22% |
| King | 4 | ~40% |
| Queen | 6 | ~55% |
| Jack | 8 | ~66% |
| Nine | 10 | ~75% |

- Every card I hold or have seen lowers `k`. An ace in my hand drops a ten lead to ~12%.
- **A proven void makes that suit safe to lead** (and, in a trump contract, makes my trumps unbeatable, §7).
- More opponents make every row worse.
- **Control decays with the tricks left [O][R].** Control matters most in the first two or three tricks. A chain
  of leads is worth its length and the counters riding on it, not a Count. Cash aces first, gamble after.

---

## 3. The auction: value both roles

**Before bidding I answer four questions.**
1. **If I win at `b`:** what is my chain? `Sure points` = aces + kept meld; `gambles` = tens, then kings.
2. **If I'm overbid:** what is this hand worth as a defender (counters I can still capture, donors I'd hold)?
3. **Compare:** `P(make)·E[total] − (1−P(make))·b` against `E[defender score] + value of the lead`. All inputs are my estimates. **[E]**
4. **Where am I in the order?** A later bidder sees my number and needs only one more. **[R]**

### Bid only what the chain covers
1. Bid the minimum, or one above the high bid, only if the bid is covered by sure points, or by sure
   points plus **one** ten-gamble. **Never rest the bid on a king.**
2. **Never bid higher than needed to win the auction.**
3. If it isn't covered, **pass**.
4. Once the bid is banked (total ≥ bid and ≥1 trick), further gambles are upside only.
5. **Dealer:** if everyone passed I'm stuck at the minimum for free, so pass. If someone bid, raise only if my chain covers the higher number.
6. **A bid is mostly noise.** Only a jump means something: it points to meld or several aces.

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

1. **Royal marriage (K+Q = 4)** → declare that suit, if I have support in it.
2. **Longest suit with an ace or ten** → declare it. *(Hand 4: A♥ T♥ Q♥ 9♥ in hearts. Jerry was void in hearts after one heart; my two top trumps and two low trumps won four tricks. **[O]** one hand.)*
3. **No suit** when strength is spread or the meld is suit-independent (pinochle, four-of-a-kinds). Marriages shrink to 2 and the 9-meld vanishes. **[V]**

A declaration is public and happens before the draw. The defender decides their draw knowing it.

**Trump vs. no-suit, why I preferred trump with one ace and a long suit [R]:** in no suit only an ace is a sure lead
(a ten loses control ~22% [E]). In trump, a long suit with its top cards is a chain of sure leads that also
forces the opponent to follow in that suit and spend his cards.

---

## 5. The draw: calculate, don't look up

Most later plays are forced, so the draw is the largest lever I have.

For every card ask: *what is it worth to keep versus a random replacement?* A random replacement is a counter
with probability 1⁄2 and an ace with probability 1⁄6.

- **A counter is worth keeping if `P(I win its trick) > 1⁄2`.** Net value of a kept counter ≈ `2·P(win) − 1`. **[R]**
- **An ace** has the highest `P(win)` when I can lead it.
- **A ten or king** needs a `P(win)` from the lead ladder or from a proven void.
- **Filler** (9 / J / Q) is worth keeping as a **defender** (safe follower). As a **declarer** it is dead weight unless it's a meld half or a trump.
- **Trump contracts:** keep every trump card I hold (they are the chain), trump 9s (meld) and K+Q pairs.

### By role
- **Declarer:** keep aces, tens backed by an ace, trump cards and meld halves. Replace dead filler. My bid must stand on counters I hold.
- **Defender vs. a no-suit bid:** "starving" (pitching every counter) helps only when his hand does *not* cover the bid, and then only ~½ point per counter. It cannot stop a bidder whose own cards cover the bid. **[R][O]**

### Open draw question, now with one data point **[O]**
*Lone off-suit king when the bid is already covered: keep or pitch?* Hand 4: I pitched K♣ (est. P(win) ~0.5, replacement worth ~+0.15 [R]) and drew a 9♠ that lost a counter to a king on the last trick. **The hand cannot tell me whether the pitch was right** (I can't replay his draw). Keep logging these. No rule yet.

---

## 6. Meld

1. **Compute their floor:** `Need = bid − their meld` counters.
2. **`bid ≤ their meld`:** only a five-trick shutout sets them. Go to damage control.
3. **`Need` large (≥ 5 at 4 players, or any bid resting on captures):** contest. Win control early and lead power.
4. **My own meld banks only with ≥1 trick.**
5. **Read names.** Each card has two copies. *(Hand 4: Jerry's empty meld told me he held no 9♥, no K+Q pair, no pinochle halves together, no four-of-a-kind. **[V]**)*
6. **Weak meld with a high bid** means the strength is in aces/tens.

---

## 7. Playing the five tricks

**Before every play:** count my legal choices (one = forced); list what is **proven** about the other hand (voids, "nothing higher");
decide whether my bid is banked (then the next lead is upside only); then apply the tie-breaks below.

**Leading**
1. **Cash aces first.** They are sure and can't be stranded once played.
2. Then the safest remaining lead by the ladder in §2, with `k` adjusted for everything seen.
3. **Void inference first:** a sluff on the last lead proves a void, so a high card in that suit is now safe.
4. Before my bid is banked, never stake it on a king.
5. Avoid leading below a king unless the alternative is worse. **[J][E]**
6. **In a trump contract, once the opponent is proven void in trump, every trump I hold is a master** [V]
   (he can't follow, and a forced trump is the only way to beat it).

**Following**
1. Win as cheaply as possible (lowest legal winner). Winning gives me the lead, so know what I'll lead next.
2. When I can't win, sluff **non-counters first**; counters get played eventually.
3. When two sluffs look equal, **keep the card that could win a later trick**.

**Tie-breaks when two plays are equal on my analysis**
1. **Play the power card before the dirt card [J].** Jerry's reason: it protects me if I've miscounted or something I hadn't thought of
   turns up. *(Hand 4 trick 3: Jerry void in hearts, so Q♥ and 9♥ were equal; I led 9♥. He'd have led Q♥. His is the better habit: it costs nothing if my proof is right and saves the better card if it is wrong.)*
2. Prefer the play that keeps more choices for later.
3. Prefer the play that doesn't let the opponent choose which of his cards to give up.

**The engine's `suggestedPlay` is a baseline, not an authority.** It suggested `9♥` where Jerry's rule and my own review say `Q♥`.
Use it as a sanity check, and log any disagreement.

**Reading:** a sluff = void in the led suit (and in trump if one exists); a low follow = nothing higher in that suit.
**A forced follow can strip counters [O]:** in hand 4 Jerry followed my A♥ with K♥, a counter given up because he had to follow suit. One observation.

---

## 8. Match management

- Every point a rival scores brings the end closer, so limiting a total matters even when I can't set it.
- Ahead: take the free minimum, pass uncovered bids. Behind late: bid covered chains only, not hope.
- When a rival is a hand from the target, defend for counters on every hand.

---

## 9. Using evidence from the table

- **Play a sound baseline against everyone.** Don't build strategy around one person's habits.
- A habit seen **fewer than ~10 times** is a hypothesis. It may shift a *close* decision; it never overrides a covered or uncovered chain.
- **Evidence that always counts, because it is forced by the rules:** meld names cards; a sluff proves a void; a low follow proves "nothing higher"; a played card is gone.
- **Evidence that is only a tendency:** bid sizes, lead order, which counters get pitched. Record it, don't rely on it.

---

## 10. Measuring myself

### Decision log (one line per bid / draw / non-forced play)
`hand | decision | role considered | my probability or EV estimate | outcome | miss or not`

### Regret check (end of each hand)
1. What was the best alternative I had at each real decision, and what would it have scored?
2. Was the loss from the **decision** or from the **cards**? Only decisions are mine to fix.
3. One sentence: what would I change, and is it supported by more than this hand?

### Scorecard so far (4 hands, 1 game)
- Bids made: 3 of 3 where I won the contract (hands 2, 3, 4). Too few to mean anything.
- Draws: 1 calculated pitch (K♣, hand 4), outcome unclear.
- Leads: 0 control losses on aces or tens in hand 4 (3 for 3).

---

## 11. Confidence ledger and priorities

| Claim | Status | Test |
|---|---|---|
| Lead-risk table (§2) | Hand-calculated | Simulate deals: how often each led rank keeps control |
| Coverage test beats Count thresholds | Reasoned | Make/set rates over 20+ hands |
| Both-role bid comparison (§3) | Reasoned, one worked example | Simulate declarer vs. defender EV by hand type |
| Keep a counter if `P(win) > 1⁄2` (§5) | Reasoned; one unresolved case | Simulate draw policies |
| Lone off-suit king: keep or pitch | One hand, undecided | Same simulation |
| Defender starve value (~½ point per counter) | Reasoned, one case | Same simulation |
| Trump contract: long suit + top trumps beats no suit | **One hand [O]** | Play more trump hands; simulate |
| Power before dirt when plays are equal | [J], logic checked | Cost is zero; nothing to test |
| Control decays with tricks left (§2) | One hand | Simulate how often a late lead converts |
| Teams rules | Untested | Play them |

**Priority 1: simulate with the real engine** (random-legal-play baselines first, then candidate policies),
against a **copy** of the engine with no database writes. That turns the [E] and [R] rows into numbers.
**Priority 2:** run the decision log and regret check every hand, and stop after each hand for review.

**Game log**

| Hand | Bid | Contract | Meld (J / C) | Counters (J / C) | Score (J / C) | Running |
|---|---|---|---|---|---|---|
| G1 H1 | Jerry 2 | No suit | 4 / 0 | 6 / 0 | 10 / 0 | 10–0 |
| G1 H2 | Claude 3 | No suit | 0 / 0 | 0 / 5 | 0 / 5 | 10–5 |
| G1 H3 *(replayed after a rewind)* | Claude 2, overbid by Jerry 3 | No suit | 0 / 0 | 3 / 1 | 3 / 1 | 13–6 |
| G1 H4 | Claude 2 (dealer, stuck at the minimum) | **Hearts** | 0 / 1 | 1 / 4 | 1 / 5 | 14–11 |
