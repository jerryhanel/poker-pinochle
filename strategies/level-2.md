# Level 2 Playbook — Poker Pinochle

Replaces `level-1.md`. This is my whole working understanding of the game, as of
2026-11-06, after the rules were checked against the engine and one real hand was played
against Jerry (a 0–10 loss that exposed the biggest gaps in Level 1).

**Confidence tags** on rules: **[V]** verified in the engine · **[O]** observed in a real hand ·
**[R]** reasoned, untested · **[E]** my estimate, unsimulated. Treat [R] and [E] as hypotheses.

**Integrity:** I play only from my own hand and what has been shown or played. I never read the
deal file, `State`, the DB, or another seat's cards.

---

## 1. How the game actually works

1. **Five cards, five tricks: every card in my hand gets played.** I can't keep a card back for
   later. A hand is therefore a bundle of *winners* (cards that take tricks), *donors* (counters
   that will land in a trick I lose), and *filler* (9 / J / Q that cost nothing to lose). **[V]**
2. **A counter belongs to whoever wins its trick.** My A / 10 / K is mine only if I win that
   trick; otherwise it is a gift to the other side. **[V]**
3. **The lead is the asset.** The trick winner leads next, and the bidder leads trick 1. The leader
   chooses the suit; everyone else must follow (and beat if able), so followers often have *no
   decision at all*. In the hand I played, I had exactly one legal card on both of the first two
   tricks. **[V][O]**
4. **The bid is a floor, not a ceiling.** The bidder scores the *full* total if it reaches the bid
   (Jerry bid 2, scored 10). Raising a bid buys no points; it only raises the chance of being set,
   which costs `−bid` and the meld. The only thing winning the auction buys is **control**: naming
   the contract and the first lead. **[V][O]**
5. **Meld is public, conditional money.** It names the exact cards in a hand, and it only banks if
   that side wins at least one trick. A bid ≤ the bidder's own meld can only be set by a five-trick
   shutout. **[V][O]**
6. **Pitching deletes a card; drawing buys a lottery ticket.** A discarded counter leaves the game.
   A replacement is, on average, 1⁄6 ace, 1⁄6 ten, 1⁄6 king and 1⁄2 filler: a 50% chance of a counter.
   So the draw is the only place where counters can actually be protected. **[V][E]**
7. **The contract changes what a card is.** In *no suit* there are no ruffs and rank is everything:
   an ace can only be beaten by an identical ace played earlier. With a *trump suit*, any trump beats
   any non-trump, and a player who is void in the led suit **must** trump (even when they can't beat
   what's down). **[V]**
8. **Information is mostly public.** Bids (jumps only), the **declaration**, **meld** and **leads** all
   reveal cards. The one thing hidden is how many cards someone drew. In a two-player game, meld plus
   leads exposed Jerry's whole hand by trick 3. **[V][O]**

---

## 2. What a card is worth

| Card | Bidder, no suit | Defender, no suit | Bidder, trump suit | Defender, trump suit |
|---|---|---|---|---|
| **Ace** | Keep. Lead it first. The only card that can't be beaten | Keep **only with a same-suit companion**; otherwise pitch **[R]** | Keep. Trump ace is the best card in the hand | Keep trump aces; side aces can be ruffed **[R]** |
| **Ten** | Keep with suit support; cash after the aces | Pitch. A donor | Keep trump tens | Pitch side tens **[R]** |
| **King** | Keep if the hand already has control; otherwise swap | Pitch. A donor | Keep trump K / K+Q pairs | Pitch side kings **[R]** |
| **Q, J, 9** | Pitch unless they are meld halves | **Keep.** Free to lose, and they cost no points | Keep meld halves / trump 9s | Keep as filler |

---

## 3. The auction

**Hand strength:** `Count = meld held now (in the suit I'd declare) + 2 per ace + 1 per ten`.

**When to open at the minimum** *(Level 1 thresholds, plus 2-player)* **[E]**:

| Table | Min bid | Open at the minimum when Count ≥ |
|---|:-:|:-:|
| 2 players | 2 | 2 |
| 3 players, cutthroat | 3 | 4 (e.g. two aces) |
| 4 players, teams | 4 | 4 |
| 5 players, cutthroat | 5 | 7 |
| 6 players, teams | 6 | 6 |

**Rules**
1. **Bid exactly what it takes to win the auction, never more.** The minimum if nobody has bid, otherwise one above the high bid, and only if Count supports it. Extra height is pure risk.
2. **Winning at the minimum is cheap; paying above it is expensive.** The set penalty equals the bid, so a bid of 2 risks 2 points to buy control. Control (the contract and the lead) is worth much more than that when I have power cards.
3. **The last bidder's minimum tells me nothing.** The dealer bids last and gets the contract at the minimum even by passing. A **jump** above the minimum is a signal: real meld or several aces. **[V][O]**
4. **Dealer:** if everyone passed, I'm stuck at the minimum for free: don't bid. If someone bid, I raise only if Count supports one more than their bid.
5. **Partner holds the high bid → pass.** Raising my own team just raises my risk.
6. **First seat in a 2-player game** *[R]*: passing hands the dealer the contract for free, with the suit chosen knowing nothing about my hand. With a middling hand (Count ~2–3), bid the minimum: the dealer must then pay *above* the minimum, with real set risk. With a hopeless hand (Count ≤ 1), pass.
7. Don't chase long shots (e.g. completing four aces from three, ~9%).

---

## 4. Declaring the contract (and what a declaration tells everyone)

**Choosing it:**
1. **Royal marriage (K+Q of a suit) = 4 meld** → declare that suit, if I also hold support (an ace/ten or length in it).
2. **Longest suit that includes an ace or ten** → declare that suit.
3. **No suit** when strength is *spread* (2+ aces/tens in different suits) and there is no marriage, or when the meld is suit-independent (pinochle Q♠+J♦ = 4, any four-of-a-kind). Under no suit marriages shrink to 2 and there's no 9-meld, but aces become unbeatable and nobody can ruff. **[V]**

**A declaration is a public signal made *before* the draw.** Defenders draw knowing it.
- *No suit* announces "I hold aces / suit-independent meld." A thoughtful defender then **dumps counters** (§5), which drains the very points the bidder hoped to harvest. A bidder planning to feed on the defender's kings should expect them to disappear. **[R]**
- A declared trump suit tells me which suit to count and which cards to protect.
- I should expect the same read from the other side of the table: my declaration costs me hidden information.

---

## 5. The draw — the most important decision in the hand

The draw is **simultaneous**: I decide without seeing what anyone else pitches, and nobody sees how
many I draw. **[V]** Draw size is not exposed to opponents.

### As a defender against a no-suit bidder
*(Jerry's rule; I lost 0–10 doing the opposite.)*

- **Pitch every pointer (A / 10 / K), except an ace that has a companion card in the same suit.** **[O][R]**
- **Keep the filler (9 / J / Q).** Pitching a filler to draw a card *adds* expected counters to a hand I want empty of them, because the replacement is a counter half the time. In the hand I lost I did the reverse: I pitched `9♠ 9♦ Q♣`, kept `K♥ K♠`, and drew `K♣`. Three kings went straight into his tricks. **[O]**
- **Why donors go:** every card is played (§1), I will mostly lose tricks to the leader's aces and tens, and a counter pitched is a counter deleted from the game, taking ~0.5 point off what the bidder can collect. **[R]**
- **Why an ace needs a companion:** a lone ace of suit X is forced onto the table when X is led. If he leads his *own* ace of X, mine only ties, and the earlier card wins, so I lose my ace into his trick. With a second X card I may follow low (both are legal: I can't beat it) and keep the ace. **[V][R]**
- **Caveat on the lone-ace half of the rule:** my rough estimate says a lone ace still wins about two tricks in three, since it wins whenever he leads any *lower* card of that suit, and he only holds the other copy ~12% of the time. Its real danger is a suit he never leads, where it ends up as a forced sluff. So treat "pitch lone aces" as unproven, follow Jerry's rule until it is simulated, and revisit. **[E]**
- A companion should be a non-counter (9 / J / Q); an ace+ten pair in one suit is acceptable. **[R]**

### As the no-suit bidder
- **Keep aces and tens** (and their suit support), plus any meld halves. Pitch fillers and unpaired low cards. Pitch unpaired kings only when the hand lacks control. Replacement cards help *me*, because I win most tricks and collect counters from them. **[R]**

### With a trump suit *(untested)*
- Keep trump cards, trump 9s, and meld halves; keep K+Q pairs. As a defender, non-trump counters are exposed to ruffing, so pitch lone side-suit kings and tens. **[R]**

### Always
- A stand-pat is legal and right when the hand is already working. The maximum discard is `5 − keepMin` (all five at 2–4 players). **[V]**

---

## 6. Meld: bank it, read it, choose a mode

1. **Compute their floor.** Their meld is public the moment the draw ends. `Need = bid − their meld` counters.
2. **If `bid ≤ their meld`**, I can't set them without winning all five tricks. Go to **damage control**: protect my counters in the draw (§5), sluff non-counters first, accept the result. **[O]**
3. **If `Need` is large** (≥ 5 at 4 players, or any bid resting on counters), **contest**: it's the only time a set is realistic.
4. **My own meld:** it banks only with ≥1 trick. A meld-heavy bidder with weak winners must make sure of one trick (lead a winner first).
5. **Read the names.** Each card has two copies. A pinochle names Q♠ and J♦; a marriage names the K and Q; fours name one card per suit. Subtract them from the 24-card grid and track which copy is where. **[V]**
6. **Weak meld + strong bid** means their strength is in aces/tens. Meld never shows those unless it is four aces.

---

## 7. Playing the five tricks

1. **Before every play, count my legal choices.** One legal card = forced. Void in the led suit with no trump in play = free sluff. Use `legalPlays` from the server. **[V]**
2. **Sluff non-counters first.** Counters get played eventually, so the order doesn't save them; it only avoids needless early gifts. The real protection happened in the draw.
3. **When I can beat the trick, I must, and I should win as cheaply as possible** (lowest legal winner). I can't duck, even over a partner. **[V]**
4. **Rebuild their hand every trick** from meld, the declared contract, and cards played. In a 2-player game by trick 3 it is usually fully known; then I can predict their next leads, my forced plays, and the final score. **[O]**
5. **The legal-move ladder is a lie detector.** A card that is neither the led suit nor trump means void in the led suit *and* in trump. Following low means nothing higher in that suit. Trumping a non-trump lead means void in the led suit. **[V]**
6. **When I hold the lead** (rare as a defender): lead an unbeatable card first, an ace in no suit, a trump ace in trump. After the aces of a suit are out, its tens are masters. Never lead a card into a known void while the other side still holds trump.
7. **Trump contracts:** count the 12 trump cards. A known trump-void can't win with trump. A defender void in a suit with trump left can ruff a side-suit ace lead, and is forced to.

---

## 8. Match management

- The target is 50 in 2- and 4-player games, 150 in 6-player, 25 × players in cutthroat for other counts. **[V]**
- Every point a rival scores brings the end closer, so limiting a bidder's total matters even when I can't set them.
- Ahead: pass marginal hands, take the free minimum, avoid being set. Behind late: take control with a meld-backed bid, not a hope-backed one.
- When a rival is within one hand of the target, defend for counters on every hand.

---

## 9. Reading Jerry *(one hand of evidence)*

- Bids the **minimum**, even with a very strong hand (Count ≈ 9 by my formula). Ignore the bid; read the declaration and the meld.
- Declared **no suit** with a pinochle and aces.
- **Leads power first**: A, A, 10, then the meld cards (Q, J). Expect aces, then tens, then the rest.
- Stronger hands will reveal more; update this after each hand.

---

## 10. What I'm unsure of

| Claim | Status | Test |
|---|---|---|
| Defender pitching all counters (and keeping filler) lowers the no-suit bidder's average total | Reasoned from one hand and the draw math | Simulate random no-suit bidders vs. defenders under different draw policies |
| Keeping a lone ace is worse than pitching it | **My estimate says the opposite** | Same simulation; compare lone-ace keep vs. pitch |
| Bidding the minimum from first seat in 2-player is better than passing | Reasoned (cost of −2 vs. control) | Observe the dealer's response |
| Trump-contract draw rules | Pure hypothesis | Play a trump hand as defender |
| The Count thresholds | Estimates only | Track make/set rates after 20+ hands |

**Game log:** G1 H1 — Jerry bid 2, no suit, meld 4, counters 6, scored 10; me 0. Running 10–0 (target 50).
