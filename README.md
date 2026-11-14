# Poker-Pinochle

A multiplayer card game that fuses the **bidding and trick-play structure of Pinochle** with **table sizes ranging from 2 to 8 players**, playable in teams or cutthroat free-for-all. Play against friends or fill empty seats with AI-driven bots.

**[▶ Play now](https://haneldev.zapto.org/poker-pinochle/)** · Table PIN: `2468`

---

## What Makes It Interesting

Pinochle is one of the few card games where your score depends on *two completely different things* — the combinations you can lay down before tricks start (meld), and the high-value cards you actually capture in tricks (counters). Those two halves pull in opposite directions: the hand that melts best isn't always the hand that plays best, and you have to bid before you know how well it'll work.

The "poker" in the name comes from the structure: players bid blind on their own hand's potential, the winner names trump and gets to redraw, and everyone else is trying to set them. It plays fast and rewards both card sense and bluffing range.

---

## The Deck

48 cards — the standard Pinochle deck. Only ranks **9, 10, J, Q, K, A** in four suits (♠ ♥ ♦ ♣), with **two identical copies of every card**.

Trick strength (high → low): **A · 10 · K · Q · J · 9**

The 10 outranks the King — the single biggest rule to internalize.

**Counters** (the cards worth a point each): A, 10, K — 24 total in the deck.

---

## How a Hand Works

### 1. Deal
Cards are dealt evenly to all players. The number per hand depends on player count.

### 2. Auction
Starting left of the dealer, each player either **bids** or **passes**. The minimum bid equals the number of players; the maximum is 20. Each bid must beat the last. If everyone passes, the dealer is **stuck** and must take it at the minimum. One round, no second chances.

### 3. Declare Trump
The bid winner names a trump suit (or **No Suit** — NS — which scores marriages but yields no nines-in-suit bonus).

### 4. Meld
Every player lays down their scoring combinations face-up. Points are **only kept if you win at least one trick** — if you get shut out of tricks, your meld is forfeited. The bidding side must reach their bid number between meld + counters combined, or they go set.

**Meld combinations:**

| Combination | Points |
|---|---|
| Marriage (K + Q, same suit) | 2 |
| Royal marriage (K + Q in trump) | 4 |
| 9 in trump (the dix) | 1 each |
| Pinochle (Q♠ + J♦) | 4 |
| Four jacks (one of each suit) | 4 |
| Four queens | 6 |
| Four kings | 8 |
| Four aces | 10 |

Duplicate copies score duplicate meld — two pinochles is 8 points, double marriages stack, etc.

### 5. Draw
The bid winner draws first (by default), then others in order. Each player discards down to their target hand size. Discarded cards are shuffled back into the remaining stock before others draw.

### 6. Play
The bid winner leads first. Players must **follow suit** if they can; otherwise they may play any card including trump. The highest trump wins; if no trump is played, the highest card of the led suit wins. Ties go to the first card played. Each captured A, 10, or K counts 1 counter point; winning the **last trick** is worth 1 extra counter.

### 7. Score
- **Bidding side:** meld (if they took a trick) + counters captured. If the total meets the bid, they score it. If not, they go **set** — they lose the bid amount from their running total (which can go negative).
- **Non-bidders:** score their own meld (if they took a trick) + their captured counters.

### 8. Game
First side to hit the target wins. Targets scale with player count — 50 points in a heads-up 2-player game, up to 200 in an 8-player game.

---

## Modes

| Mode | Players | Notes |
|---|---|---|
| **Teams** | 4, 6, or 8 | Even split; partners share meld + counters |
| **Cutthroat** | 2–8 | Every player for themselves |

---

## Bots

Any seated player can propose adding a bot to an open or abandoned seat. If every other seated human votes yes, a bot takes that seat — no host approval required. Bots use a heuristic engine to bid, declare trump, draw, and play. The play heuristic is the same logic the UI uses to suggest moves to human players.

---

## Stack

PHP 8 · MySQL · Soketi (WebSockets) · vanilla JS front-end · no frameworks
