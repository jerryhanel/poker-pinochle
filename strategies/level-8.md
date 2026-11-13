# Level 8 Playbook — Poker Pinochle

A general playbook for any game of this variant. It replaces Level 7. **Read this file first; read
`CLAUDE_AS_A_PLAYER.md` for the API mechanics.** It holds no game state: IDs, scores and hand history live on the
server and in the per-hand logs, not here.

**Lineage:** L1 rules + Count → L2 the bid is a floor → L3 control decides no-suit play → L4 both roles,
calculated draws → L5 trump contracts, power-before-dirt, review each hand → L6 aces can be ruffed in trump
contracts, bid up to my own meld → L7 a bid can be a denial; count down the opponent's hand from his meld →
**L8 bleeding: leading masters in a long suit forces the opponent to burn that suit; a break is cheap; a long
suit with the ace is a bid; the one-lead-at-a-time ladder was incomplete.**

## What Level 8 adds (everything else is Level 7, unchanged)
1. **Bleeding the table (§3).** Jerry's concept. It has an exact test, and I have checked it against the rules.
2. **A long suit with the ace is a bid (§4.9).** My Level 7 "sure points + one gamble" test scored such a hand as one point and told me to pass. That undervalued it.
3. **Corrections to Level 7 numbers (§2).** The lead ladder is one lead at a time. It does not model a *sequence* of leads, in which each lead forces the opponent to burn a card.
4. **A defender needs a way to get the lead (§8).** An ace in a suit the declarer doesn't hold scores nothing unless I win a trick some other way.

**Tags:** **[V]** verified in the engine · **[O]** observed in play (with n) · **[R]** reasoned, untested ·
**[E]** my estimate by hand arithmetic, unsimulated · **[J]** a rule Jerry taught; one school of play, not doctrine.
Treat [R] and [E] as hypotheses. A number with no tag does not belong in this file.

---

## 0. Ground rules (agreed with Jerry)

- **Integrity:** I play only from my own hand and what has been shown or played. I never read the deal file,
  `State`, the DB, or another seat's cards. Reading the public `meld` field is allowed: every seat gets it.
- **No calculators at the table.** In the real world I have only the playbook and my head. **No scripts,
  simulations or enumerations while a hand is in progress.** Hand arithmetic is fine and is tagged [E].
  Simulation is for reviewing the playbook *between* games, and only when Jerry asks.
- **Poll once per 10 seconds.** **Explain each move before making it:** the hand, the reasoning, the rule I'm
  following or deviating from. Label every deviation.
- **Stop after each hand** for review. Don't ready up for the next one until Jerry says so.
- **Don't edit docs until Jerry says so.** Evaluate first, then write.
- **Keep a per-hand log in the scratchpad.** Write it with the Write or Edit tool (UTF-8). **Never `Add-Content`
  suit symbols** — the default encoding turns them into `?`.
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
9. **Forced follow, in full.** A player holding the led suit must play a card of it. If he cannot beat the best
   card of that suit on the table, **he chooses which of his cards of that suit to give up** (`follow_any`). He
   will give up his lowest and keep his highest. If he *can* beat it, he **must**. **This is the engine under
   bleeding (§3).**
10. **Draws are simultaneous and draw sizes are hidden.** Meld, the contract, leads and sluffs are public.
    The `meld` phase never shows; it passes straight to `play`. **The declarer's suit is committed *before* the draw,
    so it reflects his original five cards, not his final hand.**
11. **Targets:** 50 at 2 and 4 players, 150 at 6, 200 at 8, `25 × players` in other cutthroat games.
12. **Dealer bids last. If everyone passes, the dealer is stuck at the minimum.** Bidding that minimum
    accomplishes nothing extra. **[O]**
13. **Dealer alternates each hand in a 2-player game** — verify `dealerSeat` in the snapshot.

---

## 2. Control

The leader controls the trick: whatever they lead is power for that trick, and the follower must beat if able.

### Lead ladder: chance a single led card loses control **[E]**
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

### Level 8 caveat on the ladder
**The ladder prices one lead on its own.** It is correct for the *first* lead of a suit. It is wrong for a
*later* lead in the same suit, because every earlier lead has already forced him to burn a card of that suit
(§1.9). Once I have led the suit twice, the Jack row above is no longer ~66%. Use the bleeding test (§3).
*Example of my own mistake [E]: I priced the Queen in A, Q, J, J at ~52% safe. After the Ace has drained him, it
is ~79% safe.*

### The table above is for NO SUIT only.
**In a trump contract a side-suit card can also be ruffed.** Risk of a ruff on a side-suit lead ≈
`P(he is void in that suit) × P(he holds a trump)`.
- Hand-calculated [E]: with 5 cards ≈ 21% void × 83% = **~17%**; with 4 cards left ≈ **~22%**.
  Observed [O], n = 2: one such ace lead held, one was ruffed.
- So a side-suit **ace in a trump contract is not "0%"** — it is roughly the ruff risk.
- **The trump suit itself has no ruff.** Trump leads follow the ladder rows with `k` = higher *trumps* unaccounted.
- Every card I hold or have seen lowers `k`. A **proven void** in a suit makes that suit safe to lead — and,
  if it is trump, makes every trump I hold a master (§8).
- **In a 2-player hand, a ruff of his lead always wins:** he has already played, so nobody is left to over-trump. [V]
- More opponents make every row worse.
- **Control decays with the tricks left [O][R].** Cash the best leads first, gamble after.

---

## 3. Bleeding the table  **[J]** *(new in L8)*

**The idea (Jerry):** hold masters on top of a long suit, e.g. A, A, T, then a J. Lead them in order. Each lead
forces every opponent who holds the suit to burn one card of it. Any one opponent rarely holds more than three,
so after the masters he is **void** and my remaining card in the suit is **good**, even if K and Q are unaccounted
for. The J is "power" because there is nothing left to beat it.

### 3.1 The exact test **[E, derived from the rules]**
Let `h` = how many cards of the suit he holds. Let `c_j` = my j-th best card of the suit, led best-first.
He cannot beat a master, so he follows with his **lowest** and keeps his **highest**.

- Lead `j` fails **only if** he still holds a card of the suit (`h ≥ j`) **and** his best card is higher than `c_j`.
- So **every lead wins (a "sweep") iff `h = 0` or his best card ≤ my h-th best card.**
- **If it fails at lead `j` and `h = j`, he is void afterwards.** My remaining cards in the suit are then masters.

*"He has at most three cards" is not the true condition. With A, Q, J, J, if he holds exactly a T and a K, I fail
even though that is only two cards. The true condition compares my h-th best to his best. Jerry's "at most
three" is right on average.*

### 3.2 The numbers **[E]** (2 players; I hold 4 cards of the suit; his 5 cards from the 43 unseen)

| He holds | 0 | 1 | 2 | 3 | 4+ |
|---|:-:|:-:|:-:|:-:|:-:|
| Chance | 34% | 44% | 19% | 3.5% | 0.25% |

| My four cards | Zero tricks lost | He ends **void with my cards still in hand** |
|---|:-:|:-:|
| A, A, T, J | 99.7% | ≥ 99.7% |
| A, T, K, J | 92.8% | ≥ 96% |
| A, Q, J, J | 79.3% | 96.3% (he holds ≤ 2) |

*(My first estimate for A, T, K, J was 78%. It was wrong: I treated the breaks as independent, and they are not.)*

### 3.3 A break is cheap **[J][E]**
- **I lose one trick, not the plan.** With A, Q, J, J against his T and K: he follows the A with the K, beats the Q
  with the T, and is now void. His T was spent on a worthless Q. My J, J are masters.
- **My probes (Q, J, J) are non-counters,** so the lost trick costs the lead and the chance to capture his beater (his own
  counter anyway), not points. His high trumps can only capture *my* counters, and I cash those first.
- **Lead order:** best card first. Cash the counters (A, A, T) while they are masters; the low probes come last.
- **True disaster** is the ~4% case where he holds three or more of the suit with high cards.

### 3.4 "Sucking in" depends on the contract **[V][R]**
- **The bled suit is trump:** once he is void, my remaining trumps **ruff-win any side-suit lead** I'm void in. I'm
  forced to trump, he can't over-trump (2 players), and I capture whatever he led.
- **No suit:** the pull only works on my own leads. After a break he keeps the lead and can cash side-suit power
  I can't ruff. **A break costs more in no suit.**
- **A side suit in a trump contract:** a drained opponent must ruff my next lead there if he has trump. Not safe
  unless trump is also drained. **Bleed trump first.**

### 3.5 What the bleed needs, and where it breaks
1. **The lead.** The declarer starts with it. A defender must win a trick first. *Observed [O], n = 1: I held four clubs
   with the ace and never got the lead, so the suit did nothing.*
2. **A long enough suit.** To leave a good card I need at least `h + 1` cards. Four is the practical length; four or
   more of one suit is only ~4% of dealt hands [E], so **it is mostly something to draw toward (§6).**
3. **His draw.** If trump is declared he knows the suit before he draws and can keep or draw trump (each
   replacement is trump ~18% [E]). My numbers assume his hand is random, so the real chance is lower. **In no suit he
   does not know which suit I am long in.** Unquantified.
4. **More opponents.** Each must be drained. Everything here is for two players.
5. **Masters.** A, A, T needs both aces in my hand. With one ace, the test in §3.1 decides.

### 3.6 Forced follow also harvests counters **[O], n = 1**
A master lead into a suit where he is short pulls out whatever he has, **including counters**. Hand 8: his A♥ lead forced
my lone T♥ out as a donor. This is a reason to avoid a *lone* counter in a short suit when he leads aces first (§6).

### 3.7 Reading a bleed against me
When he leads masters in one suit, count how many he has led. I'm being drained. My decision was made at the draw, since
the follows are forced. Keep non-counters in the suits he is likely to lead; avoid lone tens and kings.

---

## 4. The auction: value both roles

**Before bidding I answer four questions.**
1. **If I win at `b`:** what is my chain? `Sure points` = aces + kept meld; `gambles` = tens, then kings.
   In a trump contract a side ace is a *near*-sure lead (§2), not a certain one. **Add the bleed value (§4.9).**
2. **If I'm overbid:** what is this hand worth as a defender (counters I can still capture, donors I'd hold, and **how I would get the lead**)?
3. **Compare:** `P(make)·E[total] − (1−P(make))·b` against `E[defender score] + value of the lead`. All inputs are my estimates. **[E]**
4. **Where am I in the order?** A later bidder sees my number and needs only one more. **[R]**

### Bid only what the chain covers
1. Bid the minimum, or one above the high bid, only if the bid is covered by sure points, or by sure
   points plus **one** ten-gamble. **Never rest the bid on a king.** *(The bleed bid in §4.9 is the one extension.)*
2. **Never bid higher than needed to win the auction** — *except the meld rule below.*
3. **Bid my own meld [R, n = 1].** If I'm the **first** bidder and my **own meld alone covers a higher bid**, I may
   bid up to that meld. Set risk is the five-trick shutout only [V]. Observed twice [O]: a meld-5 hand bid 5 and made it;
   in hand 8 the opponent bid 4 on a 4-point pinochle and made it, and I could not touch it.
4. If it isn't covered, **pass**.
5. Once the bid is banked (total ≥ bid and ≥1 trick), further gambles are upside only.
6. **Dealer:** if everyone passed I'm stuck at the minimum for free, so pass. If someone bid, raise only if my chain covers the higher number.
7. **A bid is mostly noise.** Only a jump means something: it points to meld or several aces. A jump equal to a
   visible-later meld is the "bid up to my own meld" pattern (§4.3).
8. **The denial bid:** an opponent may overbid to take the contract from a hand he expects to be strong, then redraw
   most of his own cards. **Observed once [O], n = 1, used on me [J].** His suit was committed *before* the draw, so it
   describes his *original* cards; his bid is less covered than it looks. I may use it only if (a) beating the bid
   costs little, (b) my hand would likely make it after a heavy redraw, (c) the set cost `−b` is small against the
   declarer value I'm removing. **A denial bid also wastes a bleed hand held by the other side.**

### 4.9 **The long-suit bid  [J][E]** *(new in L8)*
**A hand with 4 cards of one suit including the ace is a bid, not a pass.** Jerry's case: A, Q, J, J of the same suit,
with the fifth card anything. Declare that suit as trump.

- **Why:** the ace is one sure point. Leading it drains him (he is void or has one card ~77% of the time [E]), and after that
  the Q, J, J are masters or near-masters. He must play all five cards into tricks I win, so I capture the counters he holds
  (he can keep at most one back for the last trick).
- **How much [E]:** bid **2 ≈ 85%**, bid **3 ≈ 50%**. A 5th card that is a counter raises the 3, because after the
  drain my 5th-card lead beats his last card unless he holds a higher card of its suit.
- **Level-8 cap [R]:** bid the minimum, or **the ace plus one** forced capture. Raise to 3 only when the 5th card is a
  counter or I hold a second ace. Never rest it on a king.
- **Compare with Level 7:** the old chain test scored this hand as 1 sure point and told me to pass. **That test is
  incomplete for a long suit.** It does not count the drain.
- **Limit [E]:** his draw can target a declared trump (§3.5.3). The estimates are for a random hand.
- **Declare trump or no suit?** Trump makes my leftover masters ruff-win his side-suit leads (§3.4). No suit hides the
  suit from his draw but makes a break costlier. Default to **trump** for this hand. **[R]**

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

## 5. Declaring the contract

1. **Royal marriage (K+Q = 4)** → declare that suit, if I have support in it. *(Observed once [O]: marriage + a 9 in the suit = meld 5, made.)*
2. **Longest suit with an ace or ten** → declare it. *(Observed once [O]: A, T, Q, 9 of one suit as trump; the opponent went void after one lead of it; four tricks.)* **With 4+ cards and the ace, this is the long-suit bid (§4.9).**
3. **No suit** when strength is spread or the meld is suit-independent (pinochle, four-of-a-kinds). Marriages shrink to 2 and the 9-meld vanishes. **[V]** *(Observed [O], n = 1: a no-suit declaration on a 4-point pinochle bid, with aces and tens in the hand.)*

A declaration is public and happens before the draw. The defender decides their draw knowing it.

**Trump vs. no-suit, one ace and a long suit [R]:** in no suit only an ace is a sure lead; in trump, a long suit
with its top cards is a chain of sure leads, **but side aces cost a ~17–22% ruff risk [E]** that no-suit does not have.

---

## 6. The draw: calculate, don't look up

Most later plays are forced, so the draw is the largest lever I have.

For every card ask: *what is it worth to keep versus a random replacement?* A random replacement is a counter
with probability 1⁄2 and an ace with probability 1⁄6.

- **A counter is worth keeping if `P(I win its trick) > 1⁄2`.** Net value of a kept counter ≈ `2·P(win) − 1`. **[R]**
- **An ace** has the highest `P(win)` when I can lead it. **A ten or king** needs a `P(win)` from the ladder or a proven void.
- **Trump contracts:** keep every trump card I hold (they are the chain), trump 9s (meld) and K+Q pairs.
- **Stand pat when every card is meld, trump, or a counter-winner.** Observed once [O], n = 1: all five cards qualified and the hand held.

### **Draw toward length  (new in L8)** **[J][R]**
- **Four cards of one suit are an asset (§3).** Keep the suit, **burn the off-suit filler**, and let the draw try to add a card of the suit or an ace.
- A **fifth card that is a counter** is worth keeping after a drain: it is a near-sure point (§4.9).
- **Do not break a long suit to chase a single counter elsewhere.**

### Count the cover I need *(from L7)*
- **Filler** (9 / J / Q) is worth keeping as a **defender** only as **sluff cover for the counters I must protect**.
  Roughly one filler per counter I hold [R]. **Anything beyond that is surplus: replace it,** exactly as a declarer
  would. A hand of mostly nines can't win tricks, so it needs aces and trumps more than it needs cover. **[J][R, n = 1]**
- **When the numbers are a wash [R]:** a break-even draw has more upside than a stand-pat when my hand has almost
  no winners. Prefer the draw, and keep trump, meld halves and my counter-protection.

### **Lone counters in a short suit (new in L8)** **[O][R]**
- **A lone ten or king in a suit is a donor** whenever the opponent leads a master of it. It is forced out (§3.6).
  *Observed [O], n = 1: his A♥ lead forced my T♥. I priced the risk at ~40% and the draw was still defensible.*
- If I am a defender against an aces-first leader, **count a lone ten/king as worth about zero, not +0.2**, and prefer to replace it.

### By role
- **Declarer:** keep aces, tens backed by an ace, trump cards and meld halves. Replace dead filler. My bid must stand on counters I hold, or on the long-suit bid (§4.9).
- **Defender vs. a no-suit bid:** "starving" (pitching every counter) helps only when his hand does *not* cover the bid, and then only ~½ point per counter. It cannot stop a bidder whose own cards cover the bid. **[R][O]**
- **Defender holding a trump nine:** it is worth 1 meld (if I win a trick) and a ruff tool (§2). Keep it.
- **Defender:** include **how I will get the lead** in the draw (§8). A hand of one ace and filler may never lead.

### Open draw question, one data point **[O]**
*Lone off-suit king when the bid is already covered: keep or pitch?* One observed case: pitched it
(est. P(win) ~0.5, replacement ~+0.15 [R]) and drew filler that later cost a counter. No rule yet.

---

## 7. Meld

1. **Compute their floor:** `Need = bid − their meld` counters.
2. **`bid ≤ their meld`:** only a five-trick shutout sets them. Go to damage control.
3. **`Need` large (≥ 5 at 4 players, or any bid resting on captures):** contest. Win control early and lead power.
4. **My own meld banks only with ≥1 trick.**
5. **Read names.** Each card has two copies. An empty meld proves the hand holds no K+Q pair, no Q♠+J♦ together, no four-of-a-kind, and **no 9 of the contract suit** (each copy scores). **[V]**
6. **Weak meld with a high bid** means the strength is in aces/tens. **An empty meld with a bid** means the bid rests entirely on captured counters.

### Meld names cards that stay in his hand
7. **Meld cards are not played away.** A marriage or a pinochle means *all* its cards sit in his hand until he plays them [V].
   Write his hand as `five cards = meld cards + cards he has played + unknown`, and cross a card off each trick.
8. **Count down every trick.** When the unknown count reaches 0, his remaining cards are **fully known** and his
   last leads are forced. *Observed [O], n = 2:* after a marriage and three plays his last two cards were the marriage
   pair (hand 7); after a pinochle and three plays his last two cards were the pinochle pair (hand 8), and I named his
   final lead (Q♠) in advance.
9. **Use it to place my own cards.** If my ten beats both cards of his marriage, then a forced lead of either one
   hands me the trick, and I should keep that ten until he must lead it. **[O]** n = 1.
10. **A draw that was heavy (a denial bid, §4.8) leaves few unknown cards:** the meld pair is often most of his hand.
11. **The meld record is public**, the same `meld` field every seat gets. Using it is not peeking.

---

## 8. Playing the five tricks

**Before every play:** count my legal choices (one = forced); list what is **proven** about the other hand
(voids, "nothing higher", **his meld cards still unplayed**); decide whether my bid is banked (then the next lead
is upside only); then apply the tie-breaks below.

**Leading**
1. **Cash aces first.** In no suit they can't lose; **in a trump contract they can be ruffed (§2)**, so ask
   *can he be void in this suit and still hold trump?* before leading a side ace. If the bid is banked, cash anyway
   — the downside is one counter.
2. **If I hold a long suit (§3), bleed it:** best card first, counters while they are masters, probes last.
3. Then the safest remaining lead by the ladder in §2, with `k` adjusted for everything seen **and for every card he has already burned in that suit**.
4. **Void inference first:** a sluff on the last lead proves a void, so a high card in that suit is now safe.
5. Before my bid is banked, never stake it on a king.
6. Avoid leading below a king unless the alternative is worse. **[J][E]** *(Exception: a probe in a bleed, §3.3.)*
7. **In a trump contract, once the opponent is proven void in trump, every trump I hold is a master** [V]
   (he can't follow, and a forced trump is the only way to beat it). **[O]** n = 1: after the opponent sluffed on a
   trump lead, my remaining trump was unbeatable and captured an ace.
8. **A trump lead that the opponent can answer with any trump hands him the trick.** Don't lead a low trump just
   to "draw trump" when I hold side aces to cash: the engine's `preserve_power` hint does exactly that and I have
   declined it three times with good results **[O]**, n = 3. *(That is a low-trump lead. A trump lead of a master is a bleed.)*
9. **When I win a trick late and he has a known last card, lead a suit he can't follow.** In a 2-player hand with
   no trump left, that lead can't lose. **[V]**

**Getting the lead as a defender  (new in L8)** **[R, n = 1]**
- The declarer leads first. **I only lead after I win a trick.** An ace in a suit he doesn't hold and won't lead may
  never score: I'll be forced to play it as my last card into his trick. *Hand 8: my A♣ was captured on the last trick
  because I never won one.*
- Before the draw, ask: *what card in this hand can win a trick he leads?* A master in a suit he must follow (his
  likely lead) is worth more than a stranded ace.

**Following**
1. Win as cheaply as possible (lowest legal winner). Winning gives me the lead, so know what I'll lead next.
2. **When forced to trump and every trump wins, play the cheapest [O], n = 1.** Open question: a
   counter-trump (K or T) played now is a *certain* capture, whereas held back it can be beaten (a trump king with
   A, A, T of trump outstanding ≈ 15% [E]). Level 8 has no rule weighing this. **Log it every time.**
3. When I can't win, sluff **non-counters first**; counters get played eventually.
4. When two sluffs look equal, **keep the card that could win a later trick**. A filler that can win cheaply
   (a J that beats a 9 lead) is worth more than a 9, which can never win as a follower. **Keep a counter that beats
   his known unplayed meld cards** (§7.9).
5. When I can't win and two sluffs are equal, **keep the ace** (the card that could win if I've miscounted).

**Tie-breaks when two plays are equal on my analysis**
1. **Play the power card before the dirt card [J].** It protects me if I've miscounted.
2. Prefer the play that keeps more choices for later.
3. Prefer the play that doesn't let the opponent choose which of his cards to give up.

**The engine's `suggestedPlay` is a baseline, not an authority.** Use it as a sanity check and log disagreements.
In the disagreements logged so far, my ladder / ace-first reasoning did better than the hint, and on free sluffs the
two have usually agreed (hand 8 included). Small sample **[O]**.

**Reading:** a sluff = void in the led suit (and in trump if one exists); a low follow = nothing higher in that suit.
**A forced follow can strip counters [O]:** an opponent forced to follow my ace with a king gave up a counter.
**A ruff proves a void** [V] and costs the ruffer a trump (a trump ten on a heart ace proves no hearts).
**His played cards plus his meld cards are his hand (§7.7).**

---

## 9. Match management

- Every point a rival scores brings the end closer, so limiting a total matters even when I can't set it.
- Ahead: take the free minimum, pass uncovered bids. Behind late: bid covered chains only, not hope.
- When a rival is a hand from the target, defend for counters on every hand.
- When the score is close, a denial bid (§4.8) costs `−b` if it fails; weigh that against the lead it takes away.

---

## 10. Using evidence from the table

- **Play a sound baseline against everyone.** Don't build strategy around one person's habits.
- A habit seen **fewer than ~10 times** is a hypothesis. It may shift a *close* decision; it never overrides a covered or uncovered chain.
- **Evidence that always counts, because it is forced by the rules:** meld names cards; a sluff proves a void; a ruff proves a void; a low follow proves "nothing higher"; a played card is gone.
- **Evidence that is only a tendency (record it, don't rely on it):** bid sizes, lead order, which counters get pitched. Keep such notes in the per-hand log, not in this playbook. *(Noted in the logs: aces were led first in hands 7 and 8.)*
- **A declaration is also information, a tendency, n = 2 [R]:** a no-suit contract is typically the choice of a hand with
  spread power. When a declarer picks no suit, treat my tens and kings as riskier than the §2 ladder says.
  Observed [O]: a ten I kept at ~22–28% risk lost to the declarer's ace; and in hand 8 his lead of A♥ took my lone T♥.
  *(Caution: a declaration reflects his original five cards, and a denial bid can mean he redrew most of them (§4.8).)*

---

## 11. Measuring myself

### Decision log (one line per bid / draw / non-forced play)
`hand | decision | role considered | my probability or EV estimate | outcome | miss or not`

### Regret check (end of each hand)
1. What was the best alternative I had at each real decision, and what would it have scored?
2. Was the loss from the **decision** or from the **cards**? Only decisions are mine to fix.
3. **A good outcome does not grade a decision.** Check the reasoning, not the result.
4. One sentence: what would I change, and is it supported by more than this hand?

*(Results, scorecards and per-game tallies belong in the per-hand logs, not here.)*

---

## 12. Confidence ledger and priorities

| Claim | Status | Test |
|---|---|---|
| Lead-risk table (§2), single lead | Hand-calculated | Simulate deals: how often each led rank keeps control |
| **Later leads in a suit are safer than the ladder says (§2, §3)** | Derived from the rules [E] | Simulate sequences |
| **Bleed test: sweep iff he has none or his best ≤ my h-th best (§3.1)** | Derived from the forced-follow rules [E] | Simulate; check the "keep the max" defence |
| **A, A, T, J 99.7% / A, T, K, J 92.8% / A, Q, J, J 79.3% (§3.2)** | Hand-calculated, random-hand assumption | Simulate, then include his draw |
| **A break is cheap: he ends void ~96% (§3.3)** | [J]; hand-calculated | Simulate points, not just tricks |
| **A long suit with the ace is a bid: 2 ≈ 85%, 3 ≈ 50% (§4.9)** | [J]; hand-calculated | Simulate declarer EV; track make rates |
| His draw lowers the bleed odds against a declared trump (§3.5.3) | Reasoned, unquantified | Simulate a defender who draws toward trump |
| Side-suit ace ruff risk ≈ 17–22% in trump (§2) | Hand-calculated; n = 2 [O] | Simulate; play more trump hands |
| Coverage test beats Count thresholds | Reasoned | Make/set rates over 20+ hands |
| Bid up to my own meld as first bidder (§4.3) | Reasoned; n = 2 [O] (mine, and his in hand 8) | More hands |
| A denial bid is a real tactic (§4.8) | Seen once, used on me [J][O]; unsimulated | Simulate the declarer EV after a heavy redraw |
| Both-role bid comparison (§4) | Reasoned, one worked example | Simulate declarer vs. defender EV by hand type |
| Keep a counter if `P(win) > 1⁄2` (§6) | Reasoned; one unresolved case | Simulate draw policies |
| **Lone ten/king in a short suit is a donor vs an aces-first leader (§6)** | n = 1 [O] | Simulate |
| Surplus filler (beyond one per counter) should be replaced by a defender (§6) | [J][R]; n = 1 | Simulate draw policies for defenders |
| **A defender needs a way to get the lead (§8)** | Reasoned; n = 1 [O] | Count how often a defender never leads |
| A break-even draw beats stand-pat when the hand has no winners (§6) | Reasoned; n = 1 | Same simulation |
| Lone off-suit king: keep or pitch | One case, undecided | Same simulation |
| Defender starve value (~½ point per counter) | Reasoned, one case | Same simulation |
| Trump contract: long suit + top trumps beats no suit | n = 2 [O] | Play more; simulate |
| Cheapest trump vs. bank the K (§8 Following 2) | n = 1 [O], undecided | Log every instance |
| Ladder choices beat the engine's `preserve_power` | n = 3 [O] | More plays |
| Power before dirt when plays are equal | [J], logic checked | Cost is zero |
| A no-suit declaration signals aces, so my tens/kings are riskier than the §2 ladder (§10) | Reasoned; n = 2 [O] | More no-suit hands |
| Meld cards stay in hand, so his last cards can be fully known (§7.7–7.9) | **[V]** for the rule, **n = 2 [O]** for the payoff | Use it every hand |
| Teams rules | Untested | Play them |
| Does Jerry bleed a side suit under a trump contract? | **Unknown** | Ask him; watch his leads |

**Priority 1:** keep the ground rules in §0 — especially **no calculators at the table**.
**Priority 2:** run the decision log and regret check every hand, and stop after each hand for review.
**Priority 3:** **use bleeding on purpose** — count suit length in the bid and the draw, and log every time it decides a hand.
**Priority 4 (between games, only if Jerry asks):** simulate with a **copy** of the engine with no database
writes, to turn the [E] and [R] rows into numbers.
