<?php
declare(strict_types=1);

require_once __DIR__ . '/Cards.php';
require_once __DIR__ . '/Deck.php';
require_once __DIR__ . '/Auction.php';
require_once __DIR__ . '/Meld.php';
require_once __DIR__ . '/Trick.php';
require_once __DIR__ . '/Score.php';

// -----------------------------------------------------------------------------
// State — authoritative in-memory state for one game and its in-flight hand.
//
// This is the seam Phase 2 (persistence) and Phase 3 (HTTP API) build on: every
// mutator validates turn + phase and throws DomainException on an illegal move,
// exactly as the API endpoints will need. No DB, no network here.
//
// Phases:  auction -> declare -> draw -> meld -> play -> scored
//
// Ratified rule defaults baked in (override via the $rules array; frozen per
// match in game.rules_json later):
//   keep_min .......................... 0 (2-5p) / 2 (6p) / 3 (7-8p)
//   stand_pat ......................... allowed (discard 0)
//   discard_counters_ok ............... yes
//   draw_order ........................ bid winner first, then clockwise
//   reshuffle_includes_round_discards . yes
//   make_is_gte ....................... yes (exact == bid is "made")
//   meld_card_reuse ................... yes (one card, two melds)
//   meld_requires_trick ............... yes (no trick won -> meld forfeited)
// -----------------------------------------------------------------------------

final class State
{
    /** @var int */   public $players;
    /** @var string */ public $mode;         // 'teams' | 'cutthroat'
    /** @var array */  public $rules;
    /** @var int */    public $target;
    /** @var array<int,array{seats:int[]}> */ public $sides;
    /** @var array<int,int> */ public $running = [];   // side_no => running total
    /** @var int */    public $handNo = 0;
    /** @var int|null */ public $dealerSeat = null;
    /** @var int|null */ public $gameWinnerSideNo = null;

    /** @var array|null current hand state */ public $hand = null;

    public function __construct(int $players, string $mode, array $rules = [])
    {
        if ($players < 2 || $players > 8) {
            throw new InvalidArgumentException('players must be 2..8');
        }
        if ($mode !== 'teams' && $mode !== 'cutthroat') {
            throw new InvalidArgumentException("mode must be 'teams' or 'cutthroat'");
        }
        if ($mode === 'teams' && $players % 2 !== 0) {
            throw new InvalidArgumentException('teams mode needs an even player count');
        }

        $this->players = $players;
        $this->mode    = $mode;

        $defaults = [
            'keep_min'                          => self::defaultKeepMin($players),
            'stand_pat'                         => true,
            'discard_counters_ok'               => true,
            'draw_order'                        => 'bid_winner_first',
            'reshuffle_includes_round_discards' => true,
            'make_is_gte'                       => true,
            'meld_card_reuse'                   => true,
            'meld_requires_trick'               => true,
        ];
        $this->rules  = array_merge($defaults, $rules);
        $this->target = isset($rules['target']) ? (int) $rules['target'] : Score::target($mode, $players);

        // sides + seat assignment
        $this->sides = [];
        if ($mode === 'teams') {
            $this->sides[0] = ['seats' => []];
            $this->sides[1] = ['seats' => []];
            for ($seat = 0; $seat < $players; $seat++) {
                $this->sides[$seat % 2]['seats'][] = $seat;   // strict A-B-A-B alternation
            }
        } else {
            for ($seat = 0; $seat < $players; $seat++) {
                $this->sides[$seat] = ['seats' => [$seat]];
            }
        }
        foreach (array_keys($this->sides) as $s) {
            $this->running[$s] = 0;
        }
    }

    public static function defaultKeepMin(int $players): int
    {
        if ($players >= 7) return 3;
        if ($players === 6) return 2;
        return 0;
    }

    public function seatSide(int $seat): int
    {
        return $this->mode === 'teams' ? $seat % 2 : $seat;
    }

    // ------------------------------------------------------------------ hand

    /** Begin a new hand. $deck must be 48 cards (already shuffled). */
    public function startHand(array $deck, ?int $dealerSeat = null): void
    {
        if ($this->gameWinnerSideNo !== null) {
            throw new DomainException('Game is already over.');
        }
        if (count($deck) !== Deck::SIZE) {
            throw new InvalidArgumentException('Deck must be 48 cards.');
        }

        if ($dealerSeat !== null) {
            $this->dealerSeat = $dealerSeat % $this->players;
        } elseif ($this->dealerSeat === null) {
            $this->dealerSeat = 0;
        } else {
            $this->dealerSeat = ($this->dealerSeat + 1) % $this->players;
        }

        $this->handNo++;
        $deal = Deck::deal($deck, $this->players, 5);

        $this->hand = [
            'phase'        => 'auction',
            'deck'         => $deck,
            'dealerSeat'   => $this->dealerSeat,
            'hands'        => $deal['hands'],
            'dealt'        => $deal['hands'],       // immutable record
            'stock'        => $deal['stock'],
            'discardPile'  => [],
            'auction'      => Auction::start($this->players, $this->dealerSeat),
            'trump'        => null,
            'bidWinnerSeat'   => null,
            'bidWinnerSideNo' => null,
            'winningBid'      => null,
            'stuck'          => false,
            'drawOrder'   => [],
            'drawPos'     => 0,
            'drawRecord'  => [],
            'meldRecord'  => [],
            'meldBySide'  => [],
            'tricks'      => [],
            'trick'       => null,          // current trick being filled
            'leaderSeat'  => null,
            'tricksWonBySide' => [],
            'countersBySide'  => [],
            'result'      => null,
        ];
        foreach (array_keys($this->sides) as $s) {
            $this->hand['tricksWonBySide'][$s] = 0;
            $this->hand['countersBySide'][$s]  = 0;
            $this->hand['meldBySide'][$s]      = 0;
        }
    }

    private function requirePhase(string $phase): void
    {
        if ($this->hand === null || $this->hand['phase'] !== $phase) {
            $have = $this->hand === null ? 'no hand' : $this->hand['phase'];
            throw new DomainException("Expected phase '{$phase}', in '{$have}'.");
        }
    }

    // --------------------------------------------------------------- auction

    public function auctionTurnSeat(): ?int
    {
        return $this->hand ? Auction::currentSeat($this->hand['auction']) : null;
    }

    public function bid(int $seat, string $action, ?int $amount = null): void
    {
        $this->requirePhase('auction');
        $this->hand['auction'] = Auction::apply($this->hand['auction'], $seat, $action, $amount);

        if (Auction::isClosed($this->hand['auction'])) {
            $res = Auction::result($this->hand['auction']);
            $this->hand['bidWinnerSeat']   = $res['winnerSeat'];
            $this->hand['bidWinnerSideNo'] = $this->seatSide($res['winnerSeat']);
            $this->hand['winningBid']      = $res['amount'];
            $this->hand['stuck']           = $res['stuck'];
            $this->hand['phase']           = 'declare';
        }
    }

    // --------------------------------------------------------------- declare

    public function declareTrump(int $seat, string $suit): void
    {
        $this->requirePhase('declare');
        if ($seat !== $this->hand['bidWinnerSeat']) {
            throw new DomainException('Only the bid winner declares trump.');
        }
        if (!in_array($suit, ['S', 'H', 'D', 'C', 'NT'], true)) {
            throw new DomainException("Trump must be S, H, D, C or NT.");
        }
        $this->hand['trump'] = $suit;

        // set the draw order
        $order = [];
        $start = $this->rules['draw_order'] === 'left_of_dealer_first'
            ? ($this->hand['dealerSeat'] + 1) % $this->players
            : $this->hand['bidWinnerSeat'];
        for ($k = 0; $k < $this->players; $k++) {
            $order[] = ($start + $k) % $this->players;
        }
        $this->hand['drawOrder'] = $order;
        $this->hand['drawPos']   = 0;
        $this->hand['phase']     = 'draw';
    }

    // ------------------------------------------------------------------ draw

    public function drawTurnSeat(): ?int
    {
        if (!$this->hand || $this->hand['phase'] !== 'draw') {
            return null;
        }
        return $this->hand['drawOrder'][$this->hand['drawPos']];
    }

    /** @param string[] $discards cards to pitch (may be []). */
    public function draw(int $seat, array $discards): void
    {
        $this->requirePhase('draw');
        $expected = $this->drawTurnSeat();
        if ($seat !== $expected) {
            throw new DomainException("Not seat {$seat}'s turn to draw (waiting on {$expected}).");
        }

        $maxDiscard = 5 - (int) $this->rules['keep_min'];
        $n = count($discards);
        if ($n > $maxDiscard) {
            throw new DomainException("May discard at most {$maxDiscard} (keep {$this->rules['keep_min']}).");
        }
        if ($n === 0 && !$this->rules['stand_pat']) {
            throw new DomainException('Standing pat is not allowed — discard at least one.');
        }

        // validate the pitch against a working copy of the hand
        $work = $this->hand['hands'][$seat];
        foreach ($discards as $c) {
            if (!Cards::isValid($c)) {
                throw new DomainException("Bad card code '{$c}'.");
            }
            if (!$this->rules['discard_counters_ok'] && Cards::isCounter($c)) {
                throw new DomainException("Discarding counters is not allowed ({$c}).");
            }
            if (!Cards::removeOne($work, $c)) {
                throw new DomainException("You don't hold {$c}.");
            }
        }

        // the pitch joins the discard pile now
        foreach ($discards as $c) {
            $this->hand['discardPile'][] = $c;
        }

        // draw n replacements; reshuffle the eligible discard pile if the stock dries up
        $drawn = [];
        for ($i = 0; $i < $n; $i++) {
            if (count($this->hand['stock']) === 0) {
                $eligible = $this->hand['discardPile'];
                if (!$this->rules['reshuffle_includes_round_discards']) {
                    foreach ($discards as $c) {
                        Cards::removeOne($eligible, $c);   // can't redraw your own just-pitched card
                    }
                }
                if (count($eligible) === 0) {
                    throw new DomainException('Stock exhausted with nothing eligible to reshuffle.');
                }
                $this->hand['stock'] = Deck::shuffle($eligible);
                foreach ($eligible as $c) {
                    Cards::removeOne($this->hand['discardPile'], $c);   // moved into the stock
                }
            }
            $drawn[] = array_shift($this->hand['stock']);
        }

        $this->hand['hands'][$seat] = array_merge($work, $drawn);
        $this->hand['drawRecord'][$seat] = ['discards' => array_values($discards), 'drawn' => $drawn];
        $this->hand['drawPos']++;

        if ($this->hand['drawPos'] >= $this->players) {
            $this->enterMeld();
        }
    }

    private function enterMeld(): void
    {
        $this->hand['phase'] = 'meld';
        foreach ($this->hand['hands'] as $seat => $cards) {
            $det = Meld::detect($cards, $this->hand['trump']);
            $this->hand['meldRecord'][$seat] = $det;
            $sideNo = $this->seatSide($seat);
            $this->hand['meldBySide'][$sideNo] += $det['total'];
        }
        // No player action in the meld phase (non-bidders lay meld cosmetically).
        $this->enterPlay();
    }

    // ------------------------------------------------------------------ play

    private function enterPlay(): void
    {
        $this->hand['phase']      = 'play';
        $this->hand['leaderSeat'] = $this->hand['bidWinnerSeat'];
        $this->startTrick($this->hand['leaderSeat']);
    }

    private function startTrick(int $leaderSeat): void
    {
        $order = [];
        for ($k = 0; $k < $this->players; $k++) {
            $order[] = ($leaderSeat + $k) % $this->players;
        }
        $this->hand['trick'] = ['leaderSeat' => $leaderSeat, 'order' => $order, 'plays' => []];
    }

    public function playTurnSeat(): ?int
    {
        if (!$this->hand || $this->hand['phase'] !== 'play') {
            return null;
        }
        $t = $this->hand['trick'];
        return $t['order'][count($t['plays'])];
    }

    /** @return string[] legal cards for a seat right now */
    public function legalPlaysFor(int $seat): array
    {
        $this->requirePhase('play');
        return Trick::legalPlays($this->hand['hands'][$seat], $this->hand['trick']['plays'], $this->hand['trump']);
    }

    public function play(int $seat, string $card): void
    {
        $this->requirePhase('play');
        $expected = $this->playTurnSeat();
        if ($seat !== $expected) {
            throw new DomainException("Not seat {$seat}'s turn to play (waiting on {$expected}).");
        }
        $held = $this->hand['hands'][$seat];
        if (!in_array($card, $held, true)) {
            throw new DomainException("You don't hold {$card}.");
        }
        if (!Trick::isLegal($held, $this->hand['trick']['plays'], $this->hand['trump'], $card)) {
            throw new DomainException("Illegal play: {$card}. You must follow, beat or trump when able.");
        }

        Cards::removeOne($this->hand['hands'][$seat], $card);
        $this->hand['trick']['plays'][] = ['seat' => $seat, 'card' => $card];

        if (count($this->hand['trick']['plays']) === $this->players) {
            $this->resolveTrick();
        }
    }

    private function resolveTrick(): void
    {
        $plays   = $this->hand['trick']['plays'];
        $trump   = $this->hand['trump'];
        $winSeat = Trick::winnerSeat($plays, $trump);
        $ctrs    = Trick::counters($plays);
        $winSide = $this->seatSide($winSeat);

        $this->hand['tricksWonBySide'][$winSide] += 1;
        $this->hand['countersBySide'][$winSide]  += $ctrs;

        $this->hand['tricks'][] = [
            'leaderSeat' => $this->hand['trick']['leaderSeat'],
            'plays'      => $plays,
            'winnerSeat' => $winSeat,
            'counters'   => $ctrs,
        ];
        $this->hand['trick'] = null;

        if (count($this->hand['tricks']) >= 5) {
            $this->scoreHand();
        } else {
            $this->hand['leaderSeat'] = $winSeat;
            $this->startTrick($winSeat);
        }
    }

    // ----------------------------------------------------------------- score

    private function scoreHand(): void
    {
        $res = Score::scoreHand(
            $this->hand['bidWinnerSideNo'],
            (int) $this->hand['winningBid'],
            $this->hand['meldBySide'],
            $this->hand['countersBySide'],
            $this->hand['tricksWonBySide']
        );

        $handScores = [];
        foreach ($res['sides'] as $s => $row) {
            $handScores[$s] = $row['handScore'];
        }
        $this->running = Score::applyToTotals($this->running, $handScores);
        $winner = Score::gameWinner($this->running, $this->target);
        if ($winner !== null) {
            $this->gameWinnerSideNo = $winner;
        }

        $res['handScores']      = $handScores;
        $res['runningAfter']    = $this->running;
        $res['gameWinnerSideNo'] = $this->gameWinnerSideNo;
        $this->hand['result']   = $res;
        $this->hand['phase']    = 'scored';
    }

    public function handResult(): ?array
    {
        return $this->hand['result'] ?? null;
    }

    public function isGameOver(): bool
    {
        return $this->gameWinnerSideNo !== null;
    }

    // -------------------------------------------------------------- snapshot

    /**
     * A view of the current state. If $viewerSeat is given, only that seat's
     * hand is revealed; everyone else is a card count. This is what a
     * reconnecting client will receive in Phase 3.
     */
    public function snapshot(?int $viewerSeat = null): array
    {
        $h = $this->hand;
        $seats = [];
        for ($seat = 0; $seat < $this->players; $seat++) {
            $seats[$seat] = [
                'seat'    => $seat,
                'sideNo'  => $this->seatSide($seat),
                'cards'   => ($viewerSeat === $seat && $h) ? array_values($h['hands'][$seat]) : null,
                'count'   => $h ? count($h['hands'][$seat]) : 0,
                'isDealer'=> $h ? ($seat === $h['dealerSeat']) : false,
            ];
        }

        return [
            'players'       => $this->players,
            'mode'          => $this->mode,
            'target'        => $this->target,
            'sides'         => $this->sides,
            'running'       => $this->running,
            'handNo'        => $this->handNo,
            'gameOver'      => $this->isGameOver(),
            'gameWinner'    => $this->gameWinnerSideNo,
            'phase'         => $h['phase'] ?? null,
            'dealerSeat'    => $h['dealerSeat'] ?? null,
            'trump'         => $h['trump'] ?? null,
            'bidWinnerSeat' => $h['bidWinnerSeat'] ?? null,
            'winningBid'    => $h['winningBid'] ?? null,
            'auction'       => $h['auction'] ?? null,
            'turnSeat'      => $this->currentTurnSeat(),
            'trick'         => $h['trick'] ?? null,
            'tricksPlayed'  => $h ? count($h['tricks']) : 0,
            'meldBySide'    => $h['meldBySide'] ?? null,
            'countersBySide'=> $h['countersBySide'] ?? null,
            'tricksWonBySide'=> $h['tricksWonBySide'] ?? null,
            'result'        => $h['result'] ?? null,
            'seats'         => $seats,
        ];
    }

    public function currentTurnSeat(): ?int
    {
        if (!$this->hand) {
            return null;
        }
        switch ($this->hand['phase']) {
            case 'auction': return $this->auctionTurnSeat();
            case 'declare': return $this->hand['bidWinnerSeat'];
            case 'draw':    return $this->drawTurnSeat();
            case 'play':    return $this->playTurnSeat();
            default:        return null;
        }
    }
}
