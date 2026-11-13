<?php
declare(strict_types=1);

namespace PokerPinochle\Engine;

use DomainException;
use InvalidArgumentException;

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

    /** @var array<int,array> seat_no => {uuid,name,sideNo,isHost,connection}. Carried, not used by the engine. */
    public $roster = [];

    /** @var array scored-hand history for the score pad. Carried, set by GameStore::load. */
    public $journal = [];

    /** @var string|null share code. Carried, set by GameStore::load. */
    public $joinCode = null;

    /** @var int[] seats that have clicked "ready for the next hand". Carried. */
    public $readyList = [];

    /** @var array<int,array{uuid:string,name:string}> spectators — not seated, no side. Carried, set by GameStore::load. */
    public $viewers = [];

    /**
     * Pre-deal team-pairing vote (teams mode only). uuid => {picks:[uuid,...], locked:bool}.
     * Carried, set by GameStore::load(); [] means no vote in progress.
     */
    public $teamVote = [];

    /**
     * Pending bot-seat vote proposals, keyed by target seat number (string
     * keys, round-trips through JSON): {"<seat>": {proposedBy, ballots:{uuid:bool}}}.
     * Carried, set by GameStore::load(); [] means no proposals pending.
     */
    public $botVotes = [];

    /** @var string|null host-set meeting link (e.g. Google Meet). Carried, set by GameStore::load. */
    public $meetUrl = null;

    public function __construct(int $players, string $mode, array $rules = [])
    {
        if ($players < 2 || $players > 8) {
            throw new InvalidArgumentException('players must be 2..8');
        }
        if ($mode !== 'teams' && $mode !== 'cutthroat') {
            throw new InvalidArgumentException("mode must be 'teams' or 'cutthroat'");
        }
        if ($mode === 'teams' && ($players < 4 || $players % 2 !== 0)) {
            throw new InvalidArgumentException('teams mode needs an even count of at least 4');
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
        if ($players >= 6) return 2;
        if ($players === 5) return 1;
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
            'inSuit'       => null,
            'bidWinnerSeat'   => null,
            'bidWinnerSideNo' => null,
            'winningBid'      => null,
            'stuck'          => false,
            'drawOrder'   => [],
            'drawRecord'  => [],
            'final'       => [],           // post-draw 5-card hands (set at meld)
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

    public function declareInSuit(int $seat, string $suit): void
    {
        $this->requirePhase('declare');
        if ($seat !== $this->hand['bidWinnerSeat']) {
            throw new DomainException('Only the bid winner names the suit.');
        }
        if (!in_array($suit, ['S', 'H', 'D', 'C', 'NS'], true)) {
            throw new DomainException("Suit must be S, H, D, C or NS.");
        }
        $this->hand['inSuit'] = $suit;

        // set the draw order
        $order = [];
        $start = $this->rules['draw_order'] === 'left_of_dealer_first'
            ? ($this->hand['dealerSeat'] + 1) % $this->players
            : $this->hand['bidWinnerSeat'];
        for ($k = 0; $k < $this->players; $k++) {
            $order[] = ($start + $k) % $this->players;
        }
        $this->hand['drawOrder'] = $order;
        $this->hand['phase']     = 'draw';
    }

    // ------------------------------------------------------------------ draw

    /** Seats that haven't discarded yet this round (any of them may act now — no turn order). */
    public function pendingDiscardSeats(): array
    {
        if (!$this->hand || $this->hand['phase'] !== 'draw') {
            return [];
        }
        $out = [];
        for ($seat = 0; $seat < $this->players; $seat++) {
            if (!isset($this->hand['drawRecord'][$seat])) {
                $out[] = $seat;
            }
        }
        return $out;
    }

    /**
     * @param string[] $discards cards to pitch (may be []).
     *
     * Every seat may discard whenever it likes — there's no turn order here,
     * only "have you gone yet this round." Nobody's replacement cards are
     * dealt until the whole table has discarded; only once the last seat
     * pitches does the engine deal replacements to everyone in draw order,
     * reshuffling the full round's discard pile back into the stock if it
     * runs dry. This keeps every seat's redraw odds identical (all drawing
     * from the same pool) instead of whoever discards first getting first
     * crack at a fatter stock.
     */
    public function draw(int $seat, array $discards): void
    {
        $this->requirePhase('draw');
        if (isset($this->hand['drawRecord'][$seat])) {
            throw new DomainException("Seat {$seat} has already discarded this round.");
        }

        $maxDiscard = 5 - (int) $this->rules['keep_min'];
        $n = count($discards);
        if ($n > $maxDiscard) {
            throw new DomainException("May discard at most {$maxDiscard} (keep {$this->rules['keep_min']}).");
        }
        if ($n === 0 && !$this->rules['stand_pat']) {
            throw new DomainException('Standing pat is not allowed — discard at least one.');
        }

        // validate + apply the pitch against the seat's hand
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
        $this->hand['hands'][$seat] = $work;

        // the pitch joins the discard pile now — replacements come later, for everyone at once
        foreach ($discards as $c) {
            $this->hand['discardPile'][] = $c;
        }

        $this->hand['drawRecord'][$seat] = ['discards' => array_values($discards), 'drawn' => []];

        if (count($this->hand['drawRecord']) >= $this->players) {
            $this->dealReplacements();
            $this->enterMeld();
        }
    }

    /** Once every seat has discarded, deal everyone's replacements from one shared pool. */
    private function dealReplacements(): void
    {
        foreach ($this->hand['drawOrder'] as $seat) {
            $discards = $this->hand['drawRecord'][$seat]['discards'];
            $n = count($discards);
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
            $this->hand['hands'][$seat] = array_merge($this->hand['hands'][$seat], $drawn);
            $this->hand['drawRecord'][$seat]['drawn'] = $drawn;
        }
    }

    private function enterMeld(): void
    {
        $this->hand['phase'] = 'meld';
        $this->hand['final'] = [];
        foreach ($this->hand['hands'] as $seat => $cards) {
            $this->hand['final'][$seat] = array_values($cards);   // the 5-card hand meld is read from
            $det = Meld::detect($cards, $this->hand['inSuit']);
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
        return Trick::legalPlays($this->hand['hands'][$seat], $this->hand['trick']['plays'], $this->hand['inSuit']);
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
        if (!Trick::isLegal($held, $this->hand['trick']['plays'], $this->hand['inSuit'], $card)) {
            throw new DomainException("Illegal play: {$card}. You must follow, beat, or play in suit when able.");
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
        $inSuit  = $this->hand['inSuit'];
        $winSeat = Trick::winnerSeat($plays, $inSuit);
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

    // --------------------------------------------------------- persistence

    /**
     * The full in-flight hand state as plain arrays/scalars — safe to
     * json_encode straight into hand.state_json. Null when no hand is active.
     */
    public function exportHand(): ?array
    {
        return $this->hand;
    }

    /** Restore a hand previously produced by exportHand() (json_decode assoc). */
    public function importHand(?array $hand): void
    {
        $this->hand = $hand;
    }

    /**
     * Rebuild the game-level shell (no hand) from stored columns. Pair with
     * importHand() to fully restore a game in progress.
     *
     * @param array<int,int> $running  side_no => running score
     */
    public static function rebuild(
        int $players,
        string $mode,
        array $rules,
        array $running,
        int $handNo,
        ?int $dealerSeat,
        ?int $gameWinnerSideNo
    ): self {
        $g = new self($players, $mode, $rules);
        foreach ($running as $s => $v) {
            $g->running[(int) $s] = (int) $v;
        }
        $g->handNo           = $handNo;
        $g->dealerSeat       = $dealerSeat === null ? null : (int) $dealerSeat;
        $g->gameWinnerSideNo = $gameWinnerSideNo === null ? null : (int) $gameWinnerSideNo;
        return $g;
    }

    /**
     * One starred strategy tip among $legalPlays — on top of the "why is this
     * legal" ladder reason, an optional nudge toward the strongest choice.
     * Returns null whenever there's no clean, confidently-correct pick:
     * better to say nothing than to guess. Cases handled:
     *
     *   Leading (priority order, first match wins):
     *   - You hold the ace in suit: lead it. On your own bid it cashes in
     *     toward making it ('lead_ace_bidder'); against an opponent's bid
     *     it grabs counters away from them early — working toward a set
     *     ('lead_ace_defender'). Either way it's a strong lead.
     *   - You hold 2+ cards in suit but not the ace: lead the low one, save
     *     the high ones for a later trick ('preserve_power').
     *   - You hold none of the in-suit but do hold an ace or ten elsewhere:
     *     lead the strongest one — a simple, count-free good lead ('lead_power').
     *   - Otherwise: lead your lowest card and hand control to your
     *     partner rather than committing a middling card ('hand_control').
     *
     *   Following:
     *   - Your own side already has the trick: feed them a counter that
     *     isn't in suit — it can't help you win later anyway, so it's free
     *     value ('feed_partner'); failing that, just play low ('safe_low_partner_winning').
     *   - The other side has the trick and you can't beat it: sluff a
     *     non-counter for free ('safe_sluff'); if every legal card is a
     *     counter, name the cheapest one to give up ('cheapest_loss').
     *   - The other side has the trick and you CAN beat it: win as cheaply
     *     as possible ('cheap_win').
     *
     * @param string[] $legalPlays
     * @param array<int,array{seat:int,card:string}> $played  the trick so far
     */
    private function suggestPlay(
        array $legalPlays,
        array $played,
        string $inSuit,
        int $viewerSeat,
        string $ladderReason,
        ?int $bidWinnerSideNo
    ): ?array {
        if (count($legalPlays) <= 1) {
            return null;   // nothing to choose between
        }

        $counters    = array_values(array_filter($legalPlays, [Cards::class, 'isCounter']));
        $nonCounters = array_values(array_filter($legalPlays, static function ($c) { return !Cards::isCounter($c); }));
        $lowest  = static function (array $cards) { $s = Cards::sortStrong($cards); return end($s); };
        $highest = static function (array $cards) { return Cards::sortStrong($cards)[0]; };

        if ($ladderReason === 'lead') {
            $inSuitPlays = $inSuit !== 'NS'
                ? array_values(array_filter($legalPlays, static function ($c) use ($inSuit) {
                    return Cards::suit($c) === $inSuit;
                }))
                : [];

            if ($inSuitPlays) {
                $ace = array_values(array_filter($inSuitPlays, static function ($c) { return Cards::rank($c) === 'A'; }));
                if ($ace) {
                    // holding the in-suit ace while leading is a strong play either
                    // way — cash it in toward your own bid, or use it to grab
                    // counters away from a bid-holding opponent (working a set)
                    $onTheBid = $this->seatSide($viewerSeat) === $bidWinnerSideNo;
                    return ['code' => $ace[0], 'reason' => $onTheBid ? 'lead_ace_bidder' : 'lead_ace_defender'];
                }
            }
            if (count($inSuitPlays) >= 2) {
                return ['code' => $lowest($inSuitPlays), 'reason' => 'preserve_power'];
            }
            if (!$inSuitPlays) {
                $power = array_values(array_filter($legalPlays, static function ($c) {
                    return Cards::rank($c) === 'A' || Cards::rank($c) === 'T';
                }));
                if ($power) {
                    return ['code' => $highest($power), 'reason' => 'lead_power'];
                }
            }
            return ['code' => $lowest($legalPlays), 'reason' => 'hand_control'];
        }

        $winIdx      = Trick::winnerIndex($played, $inSuit);
        $winningSide = $this->seatSide($played[$winIdx]['seat']);
        $mySide      = $this->seatSide($viewerSeat);

        if ($winningSide === $mySide) {
            $feedable = array_values(array_filter($counters, static function ($c) use ($inSuit) {
                return Cards::suit($c) !== $inSuit;
            }));
            if ($feedable) {
                return ['code' => $highest($feedable), 'reason' => 'feed_partner'];
            }
            if ($nonCounters) {
                return ['code' => $lowest($nonCounters), 'reason' => 'safe_low_partner_winning'];
            }
            return null;   // only in-suit counters left — no clean tip either way
        }

        $canBeat = ($ladderReason === 'follow_beat' || $ladderReason === 'insuit_beat');
        if ($canBeat) {
            return ['code' => $lowest($legalPlays), 'reason' => 'cheap_win'];
        }
        if ($nonCounters) {
            return ['code' => $lowest($nonCounters), 'reason' => 'safe_sluff'];
        }
        return ['code' => $lowest($legalPlays), 'reason' => 'cheapest_loss'];
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

        // when it's the viewer's turn to play, hand them their legal cards
        // (+ which rung of the all-beat ladder produced that list, for the "why"
        // badge, and an optional starred strategy tip on top of that — see
        // suggestPlay())
        $legalPlays = null;
        $legalPlayReason = null;
        $suggestedPlay = null;
        if ($h && $h['phase'] === 'play' && $viewerSeat !== null && $viewerSeat === $this->playTurnSeat()) {
            $legalPlays = Trick::legalPlays($h['hands'][$viewerSeat], $h['trick']['plays'], $h['inSuit']);
            $legalPlayReason = Trick::legalPlayReason($h['hands'][$viewerSeat], $h['trick']['plays'], $h['inSuit']);
            $suggestedPlay = $this->suggestPlay($legalPlays, $h['trick']['plays'], $h['inSuit'], $viewerSeat, $legalPlayReason, $h['bidWinnerSideNo']);
        }

        // cards captured in tricks so far, grouped by side (public — all played
        // cards). Each entry carries which seat actually played it — not just
        // which side ended up capturing it — so the client can show "who
        // played this" on hover.
        $capturedBySide = null;
        if ($h && !empty($h['tricks'])) {
            $capturedBySide = [];
            foreach (array_keys($this->sides) as $s) {
                $capturedBySide[$s] = [];
            }
            foreach ($h['tricks'] as $t) {
                $side = $this->seatSide($t['winnerSeat']);
                foreach ($t['plays'] as $p) {
                    $capturedBySide[$side][] = ['code' => $p['card'], 'seat' => $p['seat']];
                }
            }
        }

        // seats still occupied (haven't left) — the set that must all ready up
        $readyNeeded = [];
        foreach ($this->roster as $seat => $p) {
            if (($p['connection'] ?? 'connected') !== 'left') {
                $readyNeeded[] = (int) $seat;
            }
        }

        // pre-deal team-pairing vote (teams mode, table full, not yet resolved)
        $tvBallots      = $this->teamVote['ballots'] ?? [];
        $tvResolved     = !empty($this->teamVote['resolved']);
        $tableFull      = count($this->roster) >= $this->players;
        $teamVoteNeeded = $this->mode === 'teams' && $h === null && $tableFull && !$tvResolved;
        $teamVoteLocked = [];
        foreach ($tvBallots as $uuid => $v) {
            if (!empty($v['locked'])) {
                $teamVoteLocked[] = $uuid;
            }
        }
        $myTeamVotePicks = [];
        if ($viewerSeat !== null && isset($this->roster[$viewerSeat])) {
            $viewerUuid = $this->roster[$viewerSeat]['uuid'];
            $myTeamVotePicks = $tvBallots[$viewerUuid]['picks'] ?? [];
        }

        return [
            'players'       => $this->players,
            'mode'          => $this->mode,
            'target'        => $this->target,
            'joinCode'      => $this->joinCode,
            'meetUrl'       => $this->meetUrl,
            'sides'         => $this->sides,
            'running'       => $this->running,
            'handNo'        => $this->handNo,
            'gameOver'      => $this->isGameOver(),
            'gameWinner'    => $this->gameWinnerSideNo,
            'phase'         => $h['phase'] ?? null,
            'dealerSeat'    => $h['dealerSeat'] ?? null,
            'inSuit'        => $h['inSuit'] ?? null,
            'bidWinnerSeat' => $h['bidWinnerSeat'] ?? null,
            'bidWinnerSideNo' => $h['bidWinnerSideNo'] ?? null,
            'winningBid'    => $h['winningBid'] ?? null,
            'auction'       => $h['auction'] ?? null,
            'turnSeat'      => $this->currentTurnSeat(),
            'trick'         => $h['trick'] ?? null,
            'tricksPlayed'  => $h ? count($h['tricks']) : 0,
            'meldBySide'    => $h['meldBySide'] ?? null,
            'countersBySide'=> $h['countersBySide'] ?? null,
            'tricksWonBySide'=> $h['tricksWonBySide'] ?? null,
            'result'        => $h['result'] ?? null,
            'legalPlays'    => $legalPlays,
            'legalPlayReason' => $legalPlayReason,
            'suggestedPlay' => $suggestedPlay,
            'keepMin'       => (int) ($this->rules['keep_min'] ?? 0),
            'meld'          => ($h && !empty($h['meldRecord'])) ? $h['meldRecord'] : null,
            'lastTrick'     => ($h && !empty($h['tricks'])) ? $h['tricks'][count($h['tricks']) - 1] : null,
            'capturedBySide'=> $capturedBySide,
            'journal'       => $this->journal,
            'ready'         => array_values($this->readyList),
            'readyNeeded'   => $readyNeeded,
            'discarded'     => ($h && $h['phase'] === 'draw') ? array_keys($h['drawRecord']) : [],
            'discardNeeded' => ($h && $h['phase'] === 'draw') ? range(0, $this->players - 1) : [],
            'teamSize'        => intdiv($this->players, 2),
            'teamVoteNeeded'  => $teamVoteNeeded,
            'teamVoteLocked'  => $teamVoteLocked,
            'myTeamVotePicks' => $myTeamVotePicks,
            'botVotes'      => $this->botVotes,
            'seats'         => $seats,
            'viewerCount'   => count($this->viewers),
            'viewers'       => array_map(static function ($v) { return $v['name']; }, array_values($this->viewers)),
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
            case 'draw':    return null;   // parallel — every seat may discard whenever it likes
            case 'play':    return $this->playTurnSeat();
            default:        return null;
        }
    }
}
