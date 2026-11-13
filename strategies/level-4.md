# Level 4 Playbook — Poker Pinochle

Merges Level 3 with a review of my own play in hand 3 of game 1. It replaces Level 3.
**Level 3 tried to beat one opponent. Level 4 is meant to make me a better player against anyone.**

**Lineage:** L1 = rules, scoring, Count bidding → L2 = the bid is a floor, the draw is the key
decision → L3 = control decides no-suit play, bid by coverage → **L4 = value every decision in
both roles, replace table lookups with calculations, and measure myself.**

**Tags:** **[V]** verified in the engine · **[O]** observed in play · **[R]** reasoned, untested ·
**[E]** my estimate, unsimulated. **[J]** = a rule Jerry taught; it is one school of play, not doctrine.
Treat [R] and [E] as hypotheses. A number with no tag does not belong in this file.

**Integrity:** I play only from my own hand and what has been shown or played. I never read the
deal file, `State`, the DB, or another seat's cards.

**What changed since Level 3**
- **No opponent profile.** Reads about any one person are small-sample and don't transfer. §9 says how to use evidence from the table instead.
- **Every bid and draw is valued in both roles** (declarer / defender), not just the role I hope to get (§3).
- **The draw is done by calculation, not by a lookup table** (§5).
- **I measure myself:** a decision log with predicted vs. actual, and a regret check each hand (§10).
- Priorities for turning estimates into data (§11).

---

## 1. How the game works *(unchanged from L3; all [V] unless marked)*

1. **Five cards, five tricks: every card in my hand is played.** A hand is *winners*, *donors*
   (counters landing in tricks I lose) and *filler* (9 / J / Q).
2. **A counter belongs to whoever wins its trick.** Counters are A / 10 / K, 1 point each, 24 in the deck.
3. **The bid is a floor, not a ceiling.** The bidder scores the *full* total if it reaches the bid; if not,
   `−bid`, meld lost. Raising only adds risk. Exactly equal to the bid counts as made.
4. **Winning the auction buys only the first lead.** Control is then won trick by trick.
5. **Meld is public, conditional money.** It names exact cards and banks only if that side wins ≥1 trick.
   A bid ≤ the bidder's own meld can only be set by a five-trick shutout.
6. **Replacement cards:** 1⁄6 ace, 1⁄6 ten, 1⁄6 king, 1⁄2 filler. A random card is a counter half the time.
7. **No suit:** no ruffs; an ace is beaten only by an identical ace played earlier (ties go to the earlier card).
   **Trump suit:** a player void in the led suit **must** trump, even when they can't beat what's down.
8. **I can't duck.** If I can beat the trick I must, even over a partner. `legalPlays` from the server is exact.
9. **Draws are simultaneous and draw sizes are hidden.** Meld, the contract, leads and sluffs are public.
   **Meld is calculated automatically; the `meld` phase never shows.**
10. **Targets:** 50 at 2 and 4 players, 150 at 6, 200 at 8, `25 × players` in other cutthroat games.

---

## 2. Control *(from L3, with a correction)*

The leader controls the trick: whatever they lead is power for that trick, and the follower must beat if able.

### Lead ladder: chance a led card loses control
Assumes I hold none of the higher cards of that suit and none have been played, in a 2-player hand
(his 5 cards from 43 unseen), with `k` higher cards unaccounted for. Risk ≈ `1 − C(43−k,5)/C(43,5)`. **[E]**
His draw lets him see extra cards, so the real risk is somewhat higher.

| Lead | k | Loses control |
|:-:|:-:|:-:|
| Ace | 0 | **0%** |
| Ten | 2 | ~22% |
| King | 4 | ~40% |
| Queen | 6 | ~55% |
| Jack | 8 | ~66% |
| Nine | 10 | ~75% |

- Every card I hold or have seen lowers `k`. An ace in my hand drops a ten lead to ~12%. A sluff proves a void, so that suit is safe to lead.
- More opponents make every row worse.

### Correction: control decays with the tricks left **[O][R]**
In hand 3 I held the lead only on the last trick, which was worth nothing. In a 2-player, five-trick
hand, a lead can only convert on the tricks that remain, and an opponent who cashes a chain first
leaves nothing for a late lead to beat. So:
- **Control matters most in the first two or three tricks.** A card whose only job is to "take control
  later" has low value if it can't catch a lead early.
- **Chains.** A control chain is the run of leads I can make in a row without losing the lead. Sure
  links are aces; tens and kings are gambles at the risk above. A hand is worth the length of its
  chain and the counters riding on it, not a Count.
- **Stranded cards:** a lost gamble can leave an ace unled, a gift. Cash aces first, gamble after.

---

## 3. The auction: value both roles

**Mistake in hand 3:** I priced my hand only as a declarer. I was overbid, and the same cards were then
worth less: the ten became a donor, the ace lost most of its value. **Before bidding I answer four questions.**

1. **If I win at `b`:** what is my chain? `Sure points` = aces + kept meld; `gambles` = tens, then kings.
   `P(make)` is the chance the sure points plus the gambles I'm willing to rely on reach `b`.
2. **If I'm overbid:** what is this hand worth as a defender (counters I can still capture, donors I'd hold)?
3. **Compare:** `P(make)·E[total] − (1−P(make))·b` against `E[defender score] + value of the lead`.
   Hand 3 worked out to roughly `0.85·3 − 0.15·2 ≈ 2.2` against `≈ 1.5`. **[E]** All of those numbers are my estimates.
4. **Where am I in the order?** A later bidder sees my number and needs only one more. If they can
   cover it, an overbid costs them nothing and takes the contract from me. **[R]** Bidding the minimum
   from first seat therefore only wins the auction when the later seats can't cover it.

### Bid only what the chain covers
1. Bid the minimum, or one above the high bid, only if the bid is covered by sure points, or by sure
   points plus **one** ten-gamble. **Never rest the bid on a king.**
2. **Never bid higher than needed to win the auction.**
3. If it isn't covered, **pass**.
4. Once the bid is banked (total ≥ bid and ≥1 trick), further gambles are upside only.
5. **Dealer (bids last):** if everyone passed I'm stuck at the minimum for free, so don't bid. If someone
   bid, raise only if my chain covers the higher number.
6. **A bid is mostly noise** (a minimum bid from a covered hand and from junk look the same). Only
   a jump means something: it points to meld or several aces.

### Reference totals *(unchanged)* **[E]**

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
2. **Longest suit with an ace or ten** → declare it.
3. **No suit** when strength is spread or the meld is suit-independent (pinochle, four-of-a-kinds).
   Marriages shrink to 2 and the 9-meld vanishes. **[V]**

A declaration is public and happens before the draw. "No suit" says I hold aces; "hearts" says I hold
hearts. The defender decides their draw knowing it.

---

## 5. The draw: calculate, don't look up

The draw is the one place a hand's makeup changes and the single largest lever I have, because most
later plays are forced. In hand 3 **every** play was forced or a free sluff except one (which card to
sluff). **[O]**

### The calculation for each card
For every card, ask: *what is it worth to keep versus a random replacement?* A random replacement is
a counter with probability 1⁄2 and an ace with probability 1⁄6.

- **A counter is worth keeping if `P(I win its trick) > 1⁄2`.** If I win it I gain 1; if I lose it the other side gains 1 (a 2-point swing between "win" and "lose"). A random replacement is a counter half the time and has its own `P(win)`. **[R]**
- **An ace** has the highest `P(win)` when I can lead it; when I'm the follower it is forced out whenever he leads that suit and I hold nothing else there.
- **A ten or king** needs a `P(win)` from the lead ladder or from a proven void.
- **Filler** (9 / J / Q) is worth keeping as a **defender** because it is the safe follower: it can be forced out without donating a counter. As a **declarer** it is dead weight unless it's a meld half.
- **Short suits:** being short in the suits the other side leads costs nothing; holding a counter in those suits is a donation. I can't see which suits those will be before the draw.

### By role
- **Declarer:** keep aces, tens backed by an ace, and meld halves. Replace dead filler with lottery tickets (1⁄3 chance of an ace or ten). Keep kings only when the chain already covers the bid. **My bid must stand on counters I hold**, not on ones I hope to capture: an opponent can deny captures. **[R]**
- **Defender vs. a no-suit bid:** I can't know whether his hand covers the bid, because meld is revealed after the draw. Pitching every counter ("the starve") only helps when his hand does *not* cover the bid, and then only by about half a point per counter pitched. **[R]** It **cannot** stop a bidder whose own cards cover the bid. In hand 3 it would have changed nothing. **[O]**
- **Trump contracts:** keep trump cards, trump 9s and K+Q pairs; as a defender, a lone side-suit king or ten can be ruffed. *(Untested.)*

*My draw in hand 3 (pitch `10♣`, keep `A♣` + filler) followed an older table entry. It was not a calculated choice.
I can't claim it was right or wrong. The calculation above is what replaces it.*

---

## 6. Meld

1. **Compute their floor:** `Need = bid − their meld` counters.
2. **`bid ≤ their meld`:** only a five-trick shutout sets them. Go to damage control: sluff non-counters, don't gamble.
3. **`Need` large (≥ 5 at 4 players, or any bid resting on captures):** contest. Win control early and lead power.
4. **My own meld banks only with ≥1 trick.** A meld-heavy bidder must secure a trick on the first lead.
5. **Read names.** Each card has two copies: a pinochle names Q♠ and J♦, a marriage a K and a Q, fours one per suit. Subtract from the 24-card grid.
6. **Weak meld with a high bid** means the strength is in aces/tens, which meld never shows.

---

## 7. Playing the five tricks

**Before every play:** count my legal choices (one = forced); rebuild what the other side can hold from
meld, the contract, cards played and sluffs; decide whether my bid is banked (then the next lead is upside only).

**Leading**
1. **Cash aces first.** They are sure and can't be stranded once played.
2. Then the safest remaining lead by the ladder in §2, with `k` adjusted for everything seen.
3. **Void inference first:** a sluff on the last lead proves a void, so a high card in that suit is now safe.
4. Before my bid is banked, never stake it on a king.
5. Avoid leading below a king unless the alternative is worse: it almost surely loses control. **[J][E]**

**Following**
1. Win as cheaply as possible (lowest legal winner). Winning gives me the lead, so know what I'll lead next.
2. When I can't win, sluff **non-counters first**; counters get played eventually, so order only avoids early gifts.
3. When two sluffs look equal, **keep the card that could win a later trick** (hand 3: keeping `Q♥` over `9♥` in case a low heart was led).

**Reading:** a sluff = void in the led suit (and in trump if one exists); a low follow = nothing higher in that suit.

---

## 8. Match management

- Every point a rival scores brings the end closer, so limiting a total matters even when I can't set it.
- Ahead: take the free minimum, pass uncovered bids. Behind late: bid covered chains only, not hope.
- When a rival is a hand from the target, defend for counters on every hand.

---

## 9. Using evidence from the table *(replaces the opponent profile)*

- **Play a sound baseline against everyone.** Don't build strategy around one person's habits.
- A habit seen **fewer than ~10 times** is a hypothesis. It may shift a *close* decision; it never overrides a covered or uncovered chain.
- **Evidence that always counts, because it is forced by the rules:** meld names cards; a sluff proves a void; a low follow proves "nothing higher"; a played card is gone.
- **Evidence that is only a tendency:** bid sizes, lead order, which counters get pitched. Record it, don't rely on it.
- Reads from one table do not carry to another.

---

## 10. Measuring myself

### Decision log (one line per bid / draw / non-forced play)
`hand | decision | role considered | my probability or EV estimate | outcome | miss or not`

The point is calibration: if I say "88% the ten holds" ten times, it should hold about nine times.
Keep a running tally of estimated vs. actual for each probability band.

### Regret check (end of each hand)
1. What was the best alternative I had available at each real decision, and what would it have scored?
2. Was the loss from the **decision** or from the **cards**? Only decisions are mine to fix.
3. One sentence: what would I change, and is it supported by more than this hand?

### Scorecard (over 20+ hands)
- Make / set rate for my bids, by Count and by coverage.
- Average hand score as declarer vs. as defender.
- Draws: replaced vs. kept, and the counters that ended up in my tricks.

---

## 11. Confidence ledger and priorities

| Claim | Status | Test |
|---|---|---|
| Lead-risk table (§2) | Hand-calculated | Simulate deals: how often each led rank keeps control |
| Coverage test beats Count thresholds | Reasoned; Count shown too loose once | Make/set rates over 20+ hands |
| Both-role bid comparison (§3) | Reasoned, one worked example | Simulate declarer vs. defender EV by hand type |
| Keep a counter if `P(win) > 1⁄2` (§5) | Reasoned | Simulate draw policies |
| Defender starve value (~½ point per counter) | Reasoned, one case | Same simulation |
| Lone ace: keep vs. pitch | Conflict: [J] vs. my estimate; hand 3 did not decide it | Same simulation |
| Control decays with tricks left (§2) | One hand | Simulate how often a late lead converts |
| Teams / trump rules | Untested | Play them |

**Priority 1: simulate with the real engine** (random-legal-play baselines first, then candidate
policies), against a **copy** of the engine with no database writes. That turns the [E] and [R] rows above
into numbers, and works against any opponent.
**Priority 2:** run the decision log and regret check every hand.

**Game log**

| Hand | Bid | Contract | Meld (J / C) | Counters (J / C) | Score (J / C) | Running |
|---|---|---|---|---|---|---|
| G1 H1 | Jerry 2 | No suit | 4 / 0 | 6 / 0 | 10 / 0 | 10–0 |
| G1 H2 | Claude 3 | No suit | 0 / 0 | 0 / 5 | 0 / 5 | 10–5 |
| G1 H3 *(replayed after a rewind)* | Claude 2, overbid by Jerry 3 | No suit | 0 / 0 | 3 / 1 | 3 / 1 | 13–6 |
