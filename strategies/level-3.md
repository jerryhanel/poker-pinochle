# Level 3 Playbook — Poker Pinochle

The merge of Levels 1 and 2 with what two real hands against Jerry taught me. It replaces
both. The rules checked against the engine in Level 1 stand; the draw logic from Level 2
stands, corrected; and **control** (the lead) is now the center of everything.

**Lineage:** L1 = rules, scoring, bidding by Count → L2 = the bid is a floor, every card gets
played, the draw as the key decision → **L3 = control decides no-suit play, and my Count-based
bidding was too loose.**

**Tags:** **[V]** verified in the engine · **[O]** observed in play · **[J]** taught by Jerry ·
**[R]** reasoned, untested · **[E]** my estimate, unsimulated. Treat [R] and [E] as hypotheses.

**Integrity:** I play only from my own hand and what has been shown or played. I never read the
deal file, `State`, the DB, or another seat's cards.

**What changed since Level 2**
- A new chapter on **control** (§2): a lead ladder with the odds of losing control, and "chains".
- **Bidding is now a coverage test** (§4), not a Count threshold. My bid of 3 in hand 2 was a coin
  flip I described as 70%.
- **Cash aces first**, before any gamble (§8).
- The **defender's draw** is now a trade-off between starving the bidder and keeping control tools (§6).
- Jerry's profile now has two hands behind it, including a probable **bait** minimum bid (§10).

---

## 1. How the game works

1. **Five cards, five tricks: every card in my hand gets played.** A hand is a bundle of
   *winners*, *donors* (counters that land in tricks I lose) and *filler* (9 / J / Q). **[V]**
2. **A counter belongs to whoever wins its trick.** **[V]**
3. **The bid is a floor, not a ceiling.** The bidder scores the *full* total if it reaches the bid
   (Jerry bid 2 and scored 10). Raising the bid buys nothing but risk: a set costs `−bid` and the
   meld. **[V][O]**
4. **Winning the auction buys only the first lead.** Real control is won trick by trick (§2). **[V][J]**
5. **Meld is public, conditional money.** It names exact cards and banks only if that side wins at
   least one trick. A bid ≤ the bidder's own meld can only be set by a five-trick shutout. **[V][O]**
6. **Pitching deletes a card; drawing buys a lottery ticket.** A replacement is 1⁄6 ace, 1⁄6 ten,
   1⁄6 king, 1⁄2 filler. The draw is the only place to change a hand's makeup. **[V][E]**
7. **The contract changes what a card is.** Under *no suit* rank is everything: no ruffs, and an ace
   can only be beaten by an identical ace played earlier. Under a *trump suit* a player void in the
   led suit **must** trump, even when they can't beat what's down. **[V]**
8. **Information is mostly public:** bids (only jumps mean anything), the declaration, meld, leads and
   sluffs. Draw sizes are hidden. In a two-player game meld plus leads exposed Jerry's hand by trick 3. **[V][O]**
9. **The leader controls the trick.** What the leader leads is power for that trick; everyone else
   must follow, and must beat if they can. **[V][J]**

---

## 2. Control — the heart of no-suit play

> *"No-suit rounds are all about taking control. Once I have the control, what I lead becomes
> power for that trick. Playing a ten is a gamble, playing a king is incredibly risky, and
> anything lower than a king will almost surely lose control."* — Jerry **[J]**

### The lead ladder (chance a led card loses control)
Assuming I hold none of the higher cards of that suit, none have been played, and it's a
2-player hand (his 5 cards from 43 unseen). The risk is `1 − C(43−k,5)/C(43,5)`, where `k` is the
number of higher cards still unaccounted for. **[E]** His draw lets him see extra cards, so the
real risk is somewhat higher.

| Lead | Higher cards (k) | Loses control | In practice |
|:-:|:-:|:-:|---|
| **Ace** | 0 | **0%** | Safe. A tie goes to the earlier card, which is mine **[V]** |
| **Ten** | 2 aces | ~22% | A gamble |
| **King** | 2 aces + 2 tens | ~40% | Incredibly risky |
| **Queen** | + 2 kings | ~55% | Almost surely loses |
| **Jack** | + 2 queens | ~66% | |
| **Nine** | + 2 jacks | ~75% | |

- **Every card I hold or have seen played lowers `k`.** An ace in my hand drops a ten lead to ~12%.
- **A sluff makes a suit safe.** If he sluffs on a led suit he is void in it (§8), so `k` is effectively 0
  there. In hand 2 his Q♣ sluff made my K♠ unbeatable. **[O]**
- **More opponents make it worse:** with three opponents a king lead loses control ~83% of the time
  and a ten ~58% *[E]*. In teams, a partner who takes the trick keeps control for our side, but the
  all-beat rule forces them to beat even me.

### Chains
A **control chain** is the run of leads I can make in a row without losing the lead.
- *Sure links:* aces (each one, in any order).
- *Gamble links:* tens, then kings, each with the risk above.
- Its length, and the counters riding on it, are what a hand is worth. Count (A = 2, T = 1) is not.

### Stranded cards
A lost gamble hands the lead away. Any ace still in my hand may then never be led and ends up
as a forced sluff, a gift. **So cash the aces first and gamble afterwards.** **[R][O]**

### Control is won by tricks, not by the bid
The bid winner leads trick 1. A bidder with a junk hand leads junk, and a defender holding an
ace or ten can take control right there. A bid of 2 with a junk hand is a soft target, unless it
is a bait (§4). **[J][R]**

---

## 3. What a card is worth

| Card | Bidder, no suit | Defender, no suit | Bidder, trump suit | Defender, trump suit |
|---|---|---|---|---|
| **Ace** | Keep. Sure link; lead it first | Keep (control tool) **[R]**; Jerry's rule only keeps it with a same-suit companion **[J]** | Keep; trump ace is best | Keep trump aces; side aces can be ruffed **[R]** |
| **Ten** | A gamble link (~22%). Keep with an ace in its suit | Pitch (donor) | Keep trump tens | Pitch side tens **[R]** |
| **King** | Weak link (~40%). Keep only when the chain already covers the bid | Pitch (donor) | Keep trump K / K+Q pairs | Pitch side kings **[R]** |
| **Q, J, 9** | Dead weight: swap for a lottery ticket unless they are meld halves | **Keep**: pitching them draws counters into a hand I want empty | Keep meld halves / trump 9s | Keep as filler |

---

## 4. The auction

### Bid only what the chain covers
**Sure points** = aces (1 each, each a banked trick on the lead) + kept meld (banks with ≥1 trick).

1. **Bid the minimum, or one above the high bid, only if the bid is covered by sure points, or by sure points plus ONE ten-gamble.** *Never rest the bid on a king.*
2. If it isn't covered, **pass**. I can defend, take control later, and still collect my own captures.
3. **Never bid higher than needed to win the auction.** The score is the full total; height only adds risk. **[V][O]**
4. **Once the bid is banked** (total ≥ bid and ≥1 trick won), further gambles are upside only. Taking them is a different, cheaper decision.

**Worked examples**

| Hand | Sure points | Gamble links | Bid | Verdict |
|---|:-:|---|:-:|---|
| Jerry G1H1: `A♦ A♠ 10♠ Q♠ J♦` + pinochle | 2 aces + 4 meld = 6 | a ten | 2 | Covered with room to spare |
| Mine G1H2: `10♠ A♦ K♠ K♥ J♥` | 1 (A♦) | 10♠ (22%), then kings (~40% each) | 3 | **Not covered** (would need a ten *and* a king). Pass |
| Mine G1H1: `9♠ Q♣ 9♦ K♥ K♠` | 0 | kings | 2 | Pass |

My G1H2 bid of 3 won only because the sluff told me he had no spades. Without it, that bid sat
on a coin flip, and I'd have been set for −3.

### Reference numbers *(unchanged from Level 1)* **[E]**

| Table | Min bid | Typical total for a side |
|---|:-:|:-:|
| 2 players | 2 | ~3.5–4 |
| 3 players, cutthroat | 3 | ~3.5–4 |
| 4 players, teams | 4 | ~7 |
| 5 players, cutthroat | 5 | ~3.5–4 (min is *above* par) |
| 6 players, teams | 6 | ~10.5 |

In teams a partner adds counters and a second pair of hands, so the coverage test can count
partner-neutral sure points only. Never raise a partner's winning bid. *(Teams untested.)*

### Dealer and first-seat positions
- **Dealer (bids last):** if everyone passed, I'm stuck at the minimum for free: don't bid. If someone
  bid, I raise only if my chain covers the *higher* number.
- **First seat, minimum bid:** Jerry bids it with strong hands (G1H1) **and with junk** (G1H2:
  `9♦ Q♣ Q♥ J♦ K♦`). It tells me nothing about his hand. **It may be bait:** it risks only −2, and
  a dealer who overbids carries the real risk. Don't take bait without coverage. **[O][R]**
- **Passing as first seat** hands the dealer the bid at the minimum. It's fine with a hand I can't cover.

---

## 5. Declaring the contract

1. **Royal marriage (K+Q = 4)** → declare that suit, if I have support in it.
2. **Longest suit with an ace or ten** → declare it.
3. **No suit** when strength is spread (aces/tens in different suits) with no marriage, or when meld
   is suit-independent (pinochle Q♠+J♦ = 4; four-of-a-kinds). Marriages shrink to 2 and the 9-meld
   disappears. **[V]**

**A declaration is a public signal made before the draw.** "No suit" tells the defender I hold aces
and invites them to dump counters (§6). A bidder planning to feed on the defender's kings should
expect them to vanish. **[O]** Equally, I read theirs.

---

## 6. The draw

It is simultaneous: I decide without seeing what anyone pitches, and draw sizes are hidden. **[V]**
Max discard is `5 − keepMin` (all five in a 2–4 player game). Standing pat is legal. **[V]**

### As the no-suit bidder
- Keep aces, tens in suits where I hold an ace, and meld halves. Replace dead weight (Q / J / 9) with
  lottery tickets: a 1⁄3 chance of an ace or ten.
- Keep kings only when the chain already covers the bid; otherwise they are donors. **[R]**
- **Count my own counters.** The defender can starve me of captures (below), so my bid must stand
  on cards I hold, not on cards I hope to collect. In G1H2 I made 5 with 4 of my own counters and
  only one capture. **[O]**

### As a defender against a no-suit bid
Two schools, kept side by side:

- **The starve** **[J]**: *pitch every counter* (A / 10 / K) so the bidder's total can only come from
  his own hand. Jerry did exactly this in G1H2. It cut my captures to one card and held me to 5
  points on a bid of 3, against 10 on a bid of 2 in G1H1 when I donated three kings.
  - It **can't** stop a bidder whose own hand covers the bid, or whose meld does.
  - A pitched counter is deleted from the game, taking ~0.5 point off what the bidder can collect. **[R]**
- **Keep the filler (9 / J / Q).** Pitching filler to draw adds expected counters to a hand I want
  empty of them. In G1H1 I pitched `9♠ 9♦ Q♣`, kept both kings, and drew a third. **[O]**
- **Aces:** Jerry's rule keeps an ace only with a same-suit companion. The companion helps when he
  leads his own ace of that suit: my ace ties and loses, unless I can follow with the companion. **[V][J]**
  My read: a lone ace still wins ~⅔ of the time and is my one reliable route to taking control,
  so I lean toward **keeping aces** and pitching tens and kings. **[R]**
- **The defender decides blind.** Meld is revealed after the draw, so I can't know if his bid is
  meld-covered. A high bid suggests he needs captures, which is when the starve pays.

### Trump contracts *(untested)*
Keep trump cards, trump 9s and K+Q pairs. As a defender, pitch lone side-suit kings and tens, which
can be ruffed. **[R]**

---

## 7. Meld: bank it, read it, choose a mode

1. **Compute his floor.** `Need = bid − his meld` counters.
2. **If `bid ≤ his meld`**, only a five-trick shutout sets him. Go to **damage control**: sluff
   non-counters first and don't gamble. **[O]**
3. **If `Need` is large** (≥ 5 at 4 players, or any bid resting on captures), **contest**: win control
   with an ace or ten and lead power.
4. **My own meld banks only with ≥1 trick.** A meld-heavy bidder with weak winners must secure a trick
   on the first lead.
5. **Read names.** Each card has two copies. A pinochle names Q♠ and J♦; a marriage names the K
   and Q; fours name one card per suit. Subtract from the 24-card grid and track copies. **[V]**
6. **Weak meld with a high bid** means the strength is in aces/tens, which meld never shows.

---

## 8. Playing the five tricks

### Before every play
1. **Count my legal choices.** One legal card = forced. Void in the led suit under no suit = free
   sluff. `legalPlays` from the server is exact. **[V]**
2. **Rebuild his hand:** meld, the contract, cards played, and sluffs. By trick 3 of a 2-player hand
   it is usually known. **[O]**
3. **Decide whether my bid is banked.** If it is, the next lead is upside-only.

### Leading (with the lead)
1. **Cash aces first**, in any order. They are sure and can't be stranded once played. **[O]**
2. **Then choose the safest remaining lead** by the ladder in §2, with `k` adjusted for everything seen.
3. **Void inference first.** If he sluffed on the last lead he is void in that suit, so a high card
   there is now safe. Lead it. **[O]**
4. **Before the bid is banked, never stake it on a king.** After it is banked, a gamble costs only
   upside: G1H2's K♥ lead came with the bid already made (and a ~40% risk I accepted knowingly).
5. **Don't lead a card below a king** unless the alternative is worse. It almost surely loses control. **[J]**

### Following
1. **I can't duck.** If I can beat the trick, I must, even over a partner. **[V]**
2. **Win as cheaply as possible** (lowest legal winner). Winning gives me the lead: know what I'll
   lead next.
3. **When I can't win, sluff non-counters first.** Counters get played eventually, so order doesn't
   save them; it only avoids early gifts. The real protection was the draw. **[R]**

### Reading
- A sluff (not the led suit, no trump) means void in the led suit, and in trump if one exists.
- A low follow means nothing higher in that suit. **[V]**
- The leader's order reveals strength: Jerry led A, A, 10 and then the meld cards. **[O]**

### Trump contracts
Count the 12 trump. A known trump-void can't win with trump; a defender void in a suit with trump
left must ruff a side-suit lead. **[V]**

---

## 9. Match management

- Target 50 at 2 and 4 players, 150 at 6, `25 × players` in cutthroat otherwise. **[V]**
- Every point a rival scores brings the end closer. Limiting a total matters even when I can't set it.
- Ahead: take the free minimum, pass uncovered bids. Behind late: bid covered chains only, not hope.
- When a rival is a hand from the target, defend for counters on every hand.

---

## 10. Reading Jerry *(two hands — hypotheses)*

- **Bids the minimum** with strong and junk hands alike: no information; possibly bait (§4).
- **Starves** when I declare no suit: pitched all his counters in G1H2. **[O]**
- **Leads power first** when he holds it: A, A, 10, then meld cards.
- **Sluffs non-counters first** and keeps counters: Q♣, Q♥, J♦, and the K♦ last. So his last cards
  are probably his counters.
- **Teaches control as the main principle** (§2): expect him to defend by taking the lead.

---

## 11. Confidence ledger

| Claim | Status | Test |
|---|---|---|
| Lead-risk table (§2) | Hand-calculated | Simulate random deals; measure how often each led rank holds control |
| The coverage test beats Count thresholds | Reasoned; Count was demonstrated too loose | Track make/set rates over 20+ hands |
| The starve lowers the bidder's total | Observed once, reasoned | Simulate draw policies |
| Lone aces: keep vs. pitch | **Conflict**: Jerry's rule vs. my estimate | Same simulation |
| First-seat minimum is bait | Hypothesis | See how often a junk minimum bidder gets overbid |
| Teams / trump rules | Untested | Play them |

**Game log**

| Hand | Bid | Contract | Meld (J / C) | Counters (J / C) | Score (J / C) | Running |
|---|---|---|---|---|---|---|
| G1 H1 | Jerry 2 | No suit | 4 / 0 | 6 / 0 | 10 / 0 | 10–0 |
| G1 H2 | Claude 3 | No suit | 0 / 0 | 0 / 5 | 0 / 5 | 10–5 |
