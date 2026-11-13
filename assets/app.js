/* ===========================================================================
   Poker Pinochle — client controller
   Renders the table from api/game.php snapshots and posts player actions.
   Transport: short-poll of game.php + chat.php (Soketi push swaps in once
   window.PP.wsUrl is set).
   =========================================================================== */
(function () {
  'use strict';

  var PP = window.PP || {};
  var root = document.documentElement;
  var toastEl = document.getElementById('toast');
  var SUIT = { S: '♠', H: '♥', D: '♦', C: '♣', NS: 'N' };
  var RED = { H: 1, D: 1 };
  function rankLabel(r) { return r === 'T' ? '10' : r; }   // ten shows as "10", code stays "T"
  var PHASE_LABEL = { auction: 'Auction', declare: 'Naming in suit', draw: 'The draw', play: 'Playing', scored: 'Hand over' };
  var PLAY_REASON = {
    lead:        'You’re leading this trick — any card is fair game.',
    follow_beat: 'You must follow suit, and beat the highest card of that suit played so far.',
    follow_any:  'You must follow suit — none of your cards beat the trick so far, so any card of the suit is legal.',
    insuit_beat: 'You can’t follow suit, so you must play in suit — and beat the highest card of it played so far.',
    insuit_any:  'You can’t follow suit, so you must play in suit — none of your cards beat it, so any card in suit is legal.',
    free:        'You have nothing to follow suit or play in suit with — play anything.'
  };
  var SUGGEST_REASON = {
    lead_ace_bidder:          'Your side is on the bid — leading the ace in suit is a strong way to start pulling it in.',
    lead_ace_defender:        'The other side is on the bid — lead your ace in suit now to grab counters away from them before they can, working toward a set.',
    preserve_power:           'Leading low here saves your bigger in-suit cards to win a later trick.',
    lead_power:               'You’ve got nothing in suit, but this is strong elsewhere — a solid lead without needing to track what’s been played.',
    hand_control:             'Nothing especially strong to lead here — playing your lowest hands control to your partner.',
    feed_partner:             'Your partner’s already winning this trick, and this counter isn’t in suit — it’s safe to feed them the points now.',
    safe_low_partner_winning: 'Your partner’s already winning this trick — play low and save your better cards.',
    safe_sluff:               'You can’t win this trick, and this card isn’t worth any points — safe to throw away.',
    cheapest_loss:            'You can’t win this trick and every card you have left is a counter — this is the smallest one to give up.',
    cheap_win:                'This is your cheapest card that still takes the trick.'
  };

  var snap = null;          // last game snapshot
  var GAME = null;          // current game uuid
  var chatSince = 0;
  var pollTimer = null;
  var busy = false;         // an action POST is in flight
  var showWon = false;      // "cards won so far" panel toggled open
  var autoDealHandNo = null; // handNo we've already auto-started, so we don't double-fire
  var scoreDelayTimer = null; // holds the final trick on screen briefly before the hand-scored summary
  var meldToggle = {};      // "<handNo>:<seat>" -> explicit expand/collapse override for meld cards
  var myOrder = { game: null, handNo: null, order: [] };   // my own drag-reordered hand, this hand only

  try { GAME = localStorage.getItem('pp-game') || null; } catch (e) {}

  // ---------- helpers -------------------------------------------------------

  function el(tag, cls, txt) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (txt != null) e.textContent = txt;
    return e;
  }
  function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }
  function toast(msg, ms) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { toastEl.classList.remove('show'); }, ms || 2600);
  }

  function api(path, body) {
    var opts = { method: body ? 'POST' : 'GET', headers: { 'X-PP-CSRF': PP.csrf } };
    if (body) {
      opts.headers['Content-Type'] = 'application/json';
      body.csrf = PP.csrf;
      opts.body = JSON.stringify(body);
    }
    return fetch(PP.base + 'api/' + path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok || j.ok === false) throw new Error(j.error || ('HTTP ' + r.status));
        return j;
      });
    });
  }

  function setGame(uuid) {
    GAME = uuid || null;
    try {
      if (GAME) localStorage.setItem('pp-game', GAME);
      else localStorage.removeItem('pp-game');
    } catch (e) {}
    chatSince = 0;
    var b = document.getElementById('bubbles');
    if (b) clear(b);
  }

  function cardTile(code, opts) {
    opts = opts || {};
    var b = el(opts.button ? 'button' : 'span', 'tile');
    if (opts.button) b.type = 'button';
    var suit = code.charAt(1);
    if (RED[suit]) b.classList.add('red');
    if (opts.mini) b.classList.add('mini');
    if (opts.big) b.classList.add('big');
    if (code.charAt(0) === 'T') b.classList.add('ten');
    b.dataset.code = code;
    if (opts.down) { b.classList.add('down'); return b; }
    b.appendChild(el('span', 'r', rankLabel(code.charAt(0))));
    b.appendChild(el('span', 's', SUIT[suit]));
    b.setAttribute('aria-label', code.charAt(0) + ' of ' + { S: 'spades', H: 'hearts', D: 'diamonds', C: 'clubs' }[suit]);
    return b;
  }

  function seatName(i) { var s = snap && snap.seats[i]; return (s && s.name) || ('Seat ' + i); }
  function isMe(i) { var s = snap && snap.seats[i]; return !!(s && s.uuid === PP.me.uuid); }
  function mySeat() { return snap ? snap.viewerSeat : null; }
  function amHost() {
    if (!snap) return false;
    var m = mySeat();
    return m != null && snap.seats[m] && snap.seats[m].isHost;
  }

  // ---------- poll loop ---------------------------------------------------

  function sig(s) {
    if (!s) return '';
    return [
      s.handNo, s.phase, s.turnSeat, s.gameOver, s.winningBid, s.inSuit,
      (s.trick && s.trick.plays.length) || 0,
      (s.auction && s.auction.log.length) || 0,
      (s.journal && s.journal.length) || 0,
      (s.ready && s.ready.length) || 0,
      (s.discarded && s.discarded.length) || 0,
      s.seats.filter(function (x) { return x.uuid; }).length,
      s.seats.map(function (x) { return x.uuid || ''; }).join(','),
      s.teamVoteNeeded ? 1 : 0,
      (s.teamVoteLocked && s.teamVoteLocked.length) || 0,
      s.viewerCount || 0,
      s.meetUrl || '',
      s.seats.map(function (x) { return x.isBot ? 1 : 0; }).join(','),
      s.botVotes ? JSON.stringify(s.botVotes) : ''
    ].join('|');
  }

  // Adopt a fresh snapshot. If it just flipped play -> scored (the 5th trick
  // was completed), hold the completed trick on screen for a beat first
  // instead of jumping straight to the hand summary — otherwise the last
  // card played is never actually seen.
  function applySnapshot(j) {
    if (scoreDelayTimer) return;   // already mid-delay; let it finish on its own
    var justFinished = snap && snap.phase === 'play' && j && j.phase === 'scored' && j.lastTrick;
    if (justFinished) {
      var held = {};
      for (var k in j) { if (Object.prototype.hasOwnProperty.call(j, k)) held[k] = j[k]; }
      held.phase = 'play';
      held.trick = { leaderSeat: j.lastTrick.winnerSeat, order: [], plays: [] };
      snap = held;
      render();
      scoreDelayTimer = setTimeout(function () {
        scoreDelayTimer = null;
        snap = j;
        render();
      }, 3000);
      return;
    }
    snap = j;
    render();
  }

  function refresh() {
    if (!GAME) { renderLobby(); return Promise.resolve(); }
    return api('game.php?g=' + encodeURIComponent(GAME)).then(function (j) {
      var changed = sig(j) !== sig(snap);
      if (changed) applySnapshot(j);   // else: nothing moved — keep any in-progress input
      maybeTriggerBot(j);
    }).catch(function (e) {
      if (/no game|not seated|no such/i.test(e.message)) {
        setGame(null);
        renderLobby();
      } else {
        toast(e.message);
      }
    });
  }

  function refreshChat() {
    if (!GAME) return;
    api('chat.php?g=' + encodeURIComponent(GAME) + '&since=' + chatSince)
      .then(function (j) { (j.messages || []).forEach(addBubble); })
      .catch(function () {});
  }

  function startPolling() {
    stopPolling();
    pollTimer = setInterval(function () {
      if (busy) return;
      refresh();
      refreshChat();
    }, 2200);
  }
  function stopPolling() { if (pollTimer) clearInterval(pollTimer); pollTimer = null; }

  var botLeadDelayKey = null;   // dedupes the "bot just won, pause before leading" timer below

  // Any seated (non-bot) player's browser can drive a bot's turn along — first
  // one to notice wins; api/bot_act.php's per-phase guard makes a second,
  // racing browser's call a harmless no-op. Chains naturally: act()'s trailing
  // refresh() calls this again, so several bot turns in a row (or two bots
  // back to back) advance without anyone clicking anything.
  function maybeTriggerBot(j) {
    if (busy || !j || !j.seats || mySeat() == null) return;
    var botSeat = null;
    if (!j.phase && j.teamVoteNeeded) {
      // pre-deal team-pairing vote — every seated player (bots included) must
      // lock in before the host can deal; find a bot that hasn't yet
      var locked = j.teamVoteLocked || [];
      j.seats.some(function (s) {
        if (s.isBot && locked.indexOf(s.uuid) < 0) { botSeat = s.seat; return true; }
        return false;
      });
    } else if (j.phase === 'scored' && !j.gameOver) {
      // post-hand ready-up — a bot that never clicks would stall the host's
      // auto-deal forever
      var ready = j.ready || [];
      (j.readyNeeded || []).some(function (s) {
        if (ready.indexOf(s) < 0 && j.seats[s] && j.seats[s].isBot) { botSeat = s; return true; }
        return false;
      });
    } else if (j.phase === 'auction' || j.phase === 'declare' || j.phase === 'play') {
      if (j.turnSeat != null && j.seats[j.turnSeat] && j.seats[j.turnSeat].isBot) {
        botSeat = j.turnSeat;

        // a bot that just won the previous trick leads the next one instantly
        // otherwise — pause 5s so the just-completed trick is actually visible
        // before it's swept away by the next lead
        if (j.phase === 'play' && j.trick && j.trick.plays.length === 0 &&
            j.lastTrick && j.lastTrick.winnerSeat === botSeat) {
          var key = j.handNo + ':' + j.tricksPlayed + ':' + botSeat;
          if (botLeadDelayKey === key) return;   // already scheduled — let it fire on its own
          botLeadDelayKey = key;
          setTimeout(function () { act(api('bot_act.php', { game: GAME, seat: botSeat })); }, 5000);
          return;
        }
      }
    } else if (j.phase === 'draw') {
      var discarded = j.discarded || [];
      (j.discardNeeded || []).some(function (s) {
        if (discarded.indexOf(s) < 0 && j.seats[s] && j.seats[s].isBot) { botSeat = s; return true; }
        return false;
      });
    }
    if (botSeat == null) return;
    act(api('bot_act.php', { game: GAME, seat: botSeat }));
  }

  function act(promise) {
    busy = true;
    return promise.then(function (j) {
      if (j && j.phase) { applySnapshot(j); }
      return j;
    }).catch(function (e) {
      toast(e.message, 3500);
    }).then(function () {
      busy = false;
      refresh();
    });
  }

  // ---------- lobby ------------------------------------------------------

  function renderLobby() {
    stopPolling();
    document.getElementById('status').hidden = true;
    document.getElementById('hands').innerHTML = '<p class="empty">Not at a table yet.</p>';
    document.getElementById('score').innerHTML = '<p class="empty">No game yet.</p>';
    var meetBtn = document.getElementById('meet-btn');
    if (meetBtn) meetBtn.hidden = true;
    var meetItem = document.getElementById('meet-link-item');
    if (meetItem) meetItem.hidden = true;
    var f = document.getElementById('felt');
    clear(f);

    var box = el('div', 'lobby');
    box.appendChild(el('h2', null, 'Hi ' + PP.me.name + ' — start or join a table'));

    var cf = el('form', 'lobby-form');
    cf.appendChild(el('label', null, 'New game'));
    var row1 = el('div', 'row');
    var selP = el('select'); selP.name = 'players';
    [2, 3, 4, 5, 6, 7, 8].forEach(function (n) { var o = el('option', null, n + ' players'); o.value = n; if (n === 4) o.selected = true; selP.appendChild(o); });
    var mkBtn = el('button', 'act primary', 'Create'); mkBtn.type = 'submit';
    row1.appendChild(selP); row1.appendChild(mkBtn);
    cf.appendChild(row1);
    var modeHint = el('p', 'lobby-wait');
    function updHint() {
      var n = +selP.value;
      modeHint.textContent = (n >= 4 && n % 2 === 0)
        ? 'Two teams of ' + (n / 2) + ' — seating alternates.'
        : (n === 2 ? 'Heads-up — every player for themselves, game to 50.' : 'Cutthroat — every player for themselves.');
    }
    selP.addEventListener('change', updHint); updHint();
    cf.appendChild(modeHint);
    cf.addEventListener('submit', function (e) {
      e.preventDefault();
      api('lobby.php?action=create', { players: +selP.value }).then(function (j) {
        setGame(j.gameUuid);
        toast('Created — share code ' + j.joinCode, 4000);
        startPolling();
        refresh();
      }).catch(function (e) { toast(e.message, 3500); });
    });
    box.appendChild(cf);

    var jf = el('form', 'lobby-form');
    jf.appendChild(el('label', null, 'Join by code'));
    var row2 = el('div', 'row');
    var inC = el('input'); inC.name = 'code'; inC.maxLength = 6; inC.placeholder = 'ABC123';
    inC.style.textTransform = 'uppercase';
    var jBtn = el('button', 'act', 'Join'); jBtn.type = 'submit';
    row2.appendChild(inC); row2.appendChild(jBtn);
    jf.appendChild(row2);
    jf.addEventListener('submit', function (e) {
      e.preventDefault();
      var code = inC.value.trim().toUpperCase();
      if (!code) return;
      api('lobby.php?action=join', { code: code }).then(function (j) {
        setGame(j.gameUuid);
        toast('Joined — seat ' + j.seat);
        startPolling();
        refresh();
      }).catch(function (e) { toast(e.message, 3500); });
    });
    box.appendChild(jf);

    box.appendChild(el('h2', 'lobby-list-heading', 'Current Active Tables'));
    var list = el('div', 'lobby-list');
    box.appendChild(list);
    api('lobby.php?action=list').then(function (j) {
      if (!j.games || !j.games.length) { list.appendChild(el('p', 'empty', 'No open or active games right now.')); return; }
      j.games.forEach(function (g) {
        var open = g.status === 'lobby' && g.seated < g.playerCount;
        var bits = [g.mode, g.seated + '/' + g.playerCount + ' seated'];
        if (!open) bits.push('in progress');
        if (g.viewers) bits.push(g.viewers + ' watching');
        bits.push('code ' + g.joinCode);
        var r = el('button', 'lobby-open' + (open ? '' : ' watch-only'), bits.join(' · ') + (open ? '' : ' — watch'));
        r.addEventListener('click', function () {
          api('lobby.php?action=' + (open ? 'join' : 'watch'), { game: g.gameUuid }).then(function (jj) {
            setGame(jj.gameUuid); startPolling(); refresh();
            if (jj.viewer) toast('You’re watching — chat away, but you can’t play.', 4000);
          }).catch(function (e) { toast(e.message, 3500); });
        });
        list.appendChild(r);
      });
    }).catch(function () {});

    f.appendChild(box);
  }

  // ---------- table: hands panel --------------------------------------

  // My own hand's display order survives re-renders (polling, other players'
  // moves) but resets whenever the actual card set changes for a reason other
  // than my own reordering (new hand, cards played/discarded/drawn).
  function myHandOrder(cards) {
    if (myOrder.game !== GAME || myOrder.handNo !== snap.handNo) {
      myOrder = { game: GAME, handNo: snap.handNo, order: cards.slice() };
      return myOrder.order;
    }
    var pool = cards.slice();
    var ordered = [];
    myOrder.order.forEach(function (code) {
      var i = pool.indexOf(code);
      if (i >= 0) { ordered.push(code); pool.splice(i, 1); }
    });
    ordered = ordered.concat(pool);   // newly drawn/dealt cards land at the end
    myOrder.order = ordered;
    return ordered;
  }

  function setMyHandOrder(order) {
    myOrder = { game: GAME, handNo: snap.handNo, order: order.slice() };
  }

  // Drag-to-reorder within a row of tiles: moves the actual DOM nodes (so any
  // per-tile state — click handlers, "picked" styling — travels with them),
  // then hands `apply` the resulting left-to-right code order (read back off
  // each tile's dataset.code, so it's always consistent even after several
  // drags in a row without an intervening re-render).
  function enableDragReorder(container, apply) {
    var dragEl = null;
    Array.prototype.forEach.call(container.children, function (tile) {
      tile.draggable = true;
      tile.addEventListener('dragstart', function (e) {
        dragEl = tile;
        tile.classList.add('dragging');
        if (e.dataTransfer) {
          e.dataTransfer.effectAllowed = 'move';
          try { e.dataTransfer.setData('text/plain', tile.dataset.code || ''); } catch (er) {}
        }
      });
      tile.addEventListener('dragend', function () { tile.classList.remove('dragging'); });
      tile.addEventListener('dragover', function (e) {
        if (!dragEl || dragEl === tile) return;
        e.preventDefault();
        if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
      });
      tile.addEventListener('drop', function (e) {
        e.preventDefault();
        if (!dragEl || dragEl === tile) { dragEl = null; return; }
        var kids = Array.prototype.slice.call(container.children);
        if (kids.indexOf(dragEl) < kids.indexOf(tile)) container.insertBefore(dragEl, tile.nextSibling);
        else container.insertBefore(dragEl, tile);
        dragEl = null;
        apply(Array.prototype.map.call(container.children, function (c) { return c.dataset.code; }));
      });
    });
  }

  function renderHands() {
    var host = document.getElementById('hands');
    clear(host);
    var groups = [];
    if (snap.mode === 'teams') {
      groups = [
        { label: 'Team 1', sideNo: 0, seats: [] },
        { label: 'Team 2', sideNo: 1, seats: [] }
      ];
      snap.seats.forEach(function (s) { groups[s.sideNo].seats.push(s); });
    } else {
      groups = snap.seats.map(function (s) { return { label: s.name, sideNo: s.sideNo, seats: [s] }; });
    }

    groups.forEach(function (g) {
      var isWinner = snap.gameOver && snap.gameWinner === g.sideNo;
      var tab = el('div', 'team-tab ' + (g.sideNo === 0 ? 't1' : 't2') + (isWinner ? ' winner' : ''));
      tab.appendChild(document.createTextNode(g.label + ' '));
      var run = (snap.running && snap.running[g.sideNo] != null) ? snap.running[g.sideNo] : 0;
      var sc = el('span', 'team-score ' + (run < 0 ? 'neg' : run > 0 ? 'pos' : ''), run);
      tab.appendChild(sc);
      host.appendChild(tab);

      g.seats.forEach(function (s) {
        var row = el('div', 'player' + (snap.turnSeat === s.seat ? ' active' : ''));
        var nm = el('div', 'p-name');
        nm.appendChild(document.createTextNode(s.name || ('Seat ' + s.seat)));
        if (s.uuid === PP.me.uuid) nm.appendChild(el('span', 'badge you', 'you'));
        if (s.isBot) {
          nm.appendChild(el('span', 'badge bot', 'bot'));
          if (mySeat() != null) nm.appendChild(removeBotButton(s));
        }
        if (s.isDealer) nm.appendChild(el('span', 'badge deal', 'dealer'));
        if (snap.bidWinnerSeat === s.seat && snap.winningBid) {
          nm.appendChild(el('span', 'badge bid', 'bid ' + snap.winningBid + (snap.inSuit ? ' ' + SUIT[snap.inSuit] : '')));
        }
        if (snap.turnSeat === s.seat) nm.appendChild(el('span', 'badge turn', 'turn'));
        row.appendChild(nm);

        var cards = el('div', 'p-cards' + (s.cards ? '' : ' down'));
        if (s.cards) {
          var mine = s.uuid === PP.me.uuid;
          var ordered = mine ? myHandOrder(s.cards) : s.cards;
          var legal = (snap.phase === 'play' && snap.turnSeat === s.seat && snap.legalPlays) ? snap.legalPlays : null;
          ordered.forEach(function (code) {
            var playable = legal && legal.indexOf(code) >= 0;
            var t = cardTile(code, { button: !!legal });
            if (legal) {
              if (playable) {
                t.classList.add('legal');
                t.addEventListener('click', function () { doPlay(code); });
              } else {
                t.classList.add('blocked');
                t.disabled = true;
              }
            }
            cards.appendChild(t);
          });
          if (mine) {
            enableDragReorder(cards, function (next) { setMyHandOrder(next); render(); });
          }
        } else if (s.count > 0) {
          for (var i = 0; i < s.count; i++) cards.appendChild(cardTile('XX', { down: true, mini: true }));
          cards.appendChild(el('span', 'count', s.count));
        } else {
          cards.appendChild(el('span', 'count', '—'));
        }
        row.appendChild(cards);
        if (mySeat() != null && s.seat !== mySeat() && botVoteEligible(s)) {
          row.appendChild(botVoteControl(s));
        }
        host.appendChild(row);
      });
    });
  }

  // Any seated human may remove any bot, unilaterally — no vote needed
  // (unlike adding one). See lib/Api.php::removeBot(). Icon-only, sits right
  // next to the "bot" badge in the name row rather than down with the cards.
  function removeBotButton(s) {
    var btn = el('button', 'icon-btn remove-bot', '🚪');   // 🚪
    btn.type = 'button';
    btn.title = 'Remove bot';
    btn.setAttribute('aria-label', 'Remove bot ' + (s.name || ('seat ' + s.seat)));
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      act(api('remove_bot.php', { game: GAME, seat: s.seat }));
    });
    return btn;
  }

  // An open seat (no uuid) or one abandoned mid-game (connection === 'left')
  // that isn't already a bot — the set of targets any other seated human may
  // propose/vote a bot into. See lib/GameStore.php::botVoteSubmit().
  function botVoteEligible(s) {
    return !s.isBot && (!s.uuid || s.connection === 'left');
  }

  function botVoteControl(s) {
    var wrap = el('div', 'bot-vote');
    var entry = snap.botVotes && snap.botVotes[String(s.seat)];
    var myUuid = PP.me.uuid;

    function castVote(vote) {
      act(api('bot_vote.php', { game: GAME, seat: s.seat, vote: vote }).then(function (j) {
        if (j.botVoteResolved) toast((j.botName || 'A bot') + ' joins seat ' + s.seat + '!', 4000);
        else if (j.botVoteCancelled) toast('Bot vote for seat ' + s.seat + ' was declined.', 3000);
        return j;
      }));
    }

    if (!entry) {
      var addBtn = el('button', 'act ghost-btn', 'Add bot here');
      addBtn.type = 'button';
      addBtn.addEventListener('click', function () { castVote(true); });
      wrap.appendChild(addBtn);
    } else if (entry.ballots && Object.prototype.hasOwnProperty.call(entry.ballots, myUuid)) {
      wrap.appendChild(el('span', 'lobby-wait', 'Bot vote pending — waiting on the rest of the table.'));
    } else {
      wrap.appendChild(el('span', 'lobby-wait', 'Add a bot here?'));
      var yes = el('button', 'act ghost-btn', 'Yes');
      yes.type = 'button';
      yes.addEventListener('click', function () { castVote(true); });
      var no = el('button', 'act ghost-btn', 'No');
      no.type = 'button';
      no.addEventListener('click', function () { castVote(false); });
      wrap.appendChild(yes);
      wrap.appendChild(no);
    }
    return wrap;
  }

  // ---------- table: score pad --------------------------------------

  function renderScore() {
    var host = document.getElementById('score');
    clear(host);

    var bc = el('div', 'bid-card');
    bc.appendChild(el('span', 'k', 'This hand — the bid'));
    var v = el('span', 'v');
    if (snap.winningBid) {
      v.appendChild(el('span', 'who', seatName(snap.bidWinnerSeat)));
      v.appendChild(el('b', null, snap.winningBid));
      if (snap.inSuit) v.appendChild(el('span', 'suit' + (RED[snap.inSuit] ? ' red' : ''), SUIT[snap.inSuit]));
    } else {
      v.appendChild(el('span', 'who', '—'));
    }
    bc.appendChild(v);
    if (snap.meldBySide) {
      bc.appendChild(el('span', 'sub', 'meld  ' + sideList(snap.meldBySide)));
    }

    // running totals
    var sides = Object.keys(snap.running || {});
    var run = el('div', 'sp-running');
    sides.forEach(function (k) {
      var n = snap.running[k];
      var cell = el('div', 'sp-run' + (snap.gameWinner === +k ? ' lead' : ''));
      cell.appendChild(el('span', 'k', snap.mode === 'teams' ? ('Team ' + (+k + 1)) : seatName(+k)));
      cell.appendChild(el('span', 'n ' + (n < 0 ? 'neg' : n > 0 ? 'pos' : ''), n));
      run.appendChild(cell);
    });
    host.appendChild(run);
    host.appendChild(el('div', 'sp-goal', 'Game to ' + snap.target));
    host.appendChild(bc);

    // journal
    if (snap.journal && snap.journal.length) {
      var wrap = el('div', 'journal-wrap');
      var t = el('table', 'journal');
      var cap = el('caption', null, 'Hand-by-hand'); t.appendChild(cap);
      var thead = el('thead'); var htr = el('tr');
      htr.appendChild(el('th', null, 'Bid'));
      sides.forEach(function (k) { htr.appendChild(el('th', null, snap.mode === 'teams' ? ('T' + (+k + 1)) : seatName(+k))); });
      thead.appendChild(htr); t.appendChild(thead);
      var tb = el('tbody');
      snap.journal.forEach(function (h) {
        var tr = el('tr');
        var bidder = (h.bidderSeat != null) ? seatName(h.bidderSeat) : '';
        tr.appendChild(el('td', null,
          h.bid + (h.inSuit ? '  ' + SUIT[h.inSuit] : '') + (bidder ? ' ' + bidder : '')));
        sides.forEach(function (k) {
          var d = h.sides[k] ? h.sides[k].delta : 0;
          var td = el('td', d > 0 ? 'pos' : d < 0 ? 'neg' : null, (d > 0 ? '+' : '') + d);
          tr.appendChild(td);
        });
        tb.appendChild(tr);
      });
      t.appendChild(tb);
      wrap.appendChild(t);
      host.appendChild(wrap);
    }
  }

  function sideList(map) {
    return Object.keys(map).map(function (k) {
      return (snap.mode === 'teams' ? 'T' + (+k + 1) : ('#' + k)) + ' ' + map[k];
    }).join('   ');
  }

  // ---------- table: status + felt ----------------------------------

  function renderStatus() {
    var st = document.getElementById('status'); st.hidden = false;
    var m = document.getElementById('status-main'); clear(m);
    m.appendChild(el('span', null, 'Hand ' + (snap.handNo || 0) + ' · ' + (PHASE_LABEL[snap.phase] || 'Lobby')));
    if (snap.winningBid) {
      m.appendChild(el('span', null, 'bid: ' + seatName(snap.bidWinnerSeat) + ' ' + snap.winningBid + (snap.inSuit ? ' ' + SUIT[snap.inSuit] : '')));
    } else if (snap.inSuit) {
      m.appendChild(el('span', null, 'In Suit ' + SUIT[snap.inSuit]));
    }
    if (snap.phase === 'play') m.appendChild(el('span', null, 'Trick ' + ((snap.tricksPlayed || 0) + 1) + ' of 5'));

    var tn = document.getElementById('status-turn');
    if (snap.gameOver) tn.textContent = (snap.mode === 'teams' ? 'Team ' + (snap.gameWinner + 1) : seatName(snap.gameWinner)) + ' wins the game!';
    else if (snap.phase === 'scored') tn.textContent = 'Hand scored.';
    else if (snap.phase === 'lobby' || !snap.phase) tn.textContent = 'Waiting to start.';
    else if (snap.phase === 'draw') {
      var doneN = (snap.discarded || []).length, needN = (snap.discardNeeded || []).length;
      var meDone = mySeat() != null && snap.discarded && snap.discarded.indexOf(mySeat()) >= 0;
      tn.textContent = (mySeat() != null && !meDone ? 'Your discard — ' : '') + doneN + ' of ' + needN + ' discarded so far.';
    }
    else if (snap.turnSeat != null) tn.textContent = isMe(snap.turnSeat) ? 'Your turn.' : (seatName(snap.turnSeat) + '’s turn.');
    else tn.textContent = '';
  }

  function renderFelt() {
    var f = document.getElementById('felt');
    clear(f);
    var ph = snap.phase;

    if (mySeat() == null) {
      f.appendChild(el('p', 'watching-banner', 'You’re watching this table — chat away, but you can’t play.'));
    }

    if (!ph || ph === 'lobby') { feltLobbyWait(f); return; }
    if (ph === 'auction') { feltAuction(f); return; }
    if (ph === 'declare') { feltDeclare(f); return; }
    if (ph === 'draw') { feltDraw(f); return; }
    if (ph === 'play') { feltPlay(f); return; }
    if (ph === 'scored') { feltScored(f); return; }
  }

  function feltLobbyWait(f) {
    var box = el('div', 'lobby');
    var seated = snap.seats.filter(function (s) { return s.uuid; }).length;
    box.appendChild(el('h2', null, 'Table ready when ' + snap.players + ' are seated'));
    box.appendChild(el('p', 'lobby-wait', seated + ' / ' + snap.players + ' seated'));
    if (snap.joinCode) box.appendChild(el('div', 'lobby-code', 'share code: ' + snap.joinCode));

    if (snap.teamVoteNeeded) {
      feltTeamVote(box);
    } else if (amHost() && seated >= snap.players) {
      var b = el('button', 'act primary', 'Deal the first hand');
      b.addEventListener('click', function () { act(api('start.php', { game: GAME })); });
      box.appendChild(b);
    } else if (!amHost()) {
      box.appendChild(el('p', 'lobby-wait', 'Waiting for the host to deal.'));
    }
    var leave = el('button', 'act ghost-btn', 'Leave table');
    leave.addEventListener('click', doLeave);
    box.appendChild(leave);
    f.appendChild(box);
  }

  // Required pre-deal step for a full teams-mode lobby: everyone picks their
  // desired teammate(s); if enough players describe the same split it wins,
  // a tie at the top is randomized. See lib/GameStore.php::teamVoteSubmit().
  function feltTeamVote(box) {
    var me = mySeat();
    var meUuid = me != null ? snap.seats[me].uuid : null;
    var teamSize = snap.teamSize || 1;
    var need = teamSize - 1;
    var locked = snap.teamVoteLocked || [];
    var seatedSeats = snap.seats.filter(function (s) { return s.uuid; });

    box.appendChild(el('h2', null, 'Pick your team'));
    box.appendChild(el('p', 'lobby-wait',
      'Everyone picks ' + need + ' teammate' + (need === 1 ? '' : 's') + '. If enough players agree on ' +
      'the same split, that’s the teams — a tie at the top gets randomized.'));

    var lr = el('div', 'ready-row');
    lr.appendChild(el('div', 'ready-count', 'Locked in: ' + locked.length + ' / ' + snap.players));
    lr.appendChild(el('div', 'ready-names', seatedSeats.map(function (s) {
      return (locked.indexOf(s.uuid) >= 0 ? '✓ ' : '· ') + s.name;
    }).join('    ')));
    box.appendChild(lr);

    if (me == null) return;   // watching — nothing to vote on

    if (locked.indexOf(meUuid) >= 0) {
      box.appendChild(el('p', 'lobby-wait', 'You’re locked in — waiting for the rest of the table.'));
      return;
    }

    var picked = (snap.myTeamVotePicks || []).slice();
    var row = el('div', 'team-vote-row');
    var lockBtn;
    function refreshLockBtn() {
      lockBtn.disabled = picked.length !== need;
      lockBtn.textContent = picked.length === need
        ? 'Lock in'
        : 'Pick ' + (need - picked.length) + ' more';
    }
    seatedSeats.filter(function (s) { return s.uuid !== meUuid; }).forEach(function (s) {
      var chip = el('button', 'team-vote-pick' + (picked.indexOf(s.uuid) >= 0 ? ' picked' : ''), s.name);
      chip.type = 'button';
      chip.addEventListener('click', function () {
        var i = picked.indexOf(s.uuid);
        if (i >= 0) { picked.splice(i, 1); chip.classList.remove('picked'); }
        else if (picked.length < need) { picked.push(s.uuid); chip.classList.add('picked'); }
        refreshLockBtn();
      });
      row.appendChild(chip);
    });
    box.appendChild(row);

    lockBtn = el('button', 'act primary', 'Pick ' + need + ' more');
    lockBtn.disabled = true;
    lockBtn.addEventListener('click', function () {
      act(api('teamvote.php', { game: GAME, picks: picked, lock: true }).then(function (j) {
        if (j.teamVoteResolved) {
          toast(j.teamVoteMethod === 'random'
            ? 'No consensus — teams randomized!'
            : 'Teams decided by vote!', 4000);
        }
        return j;
      }));
    });
    box.appendChild(lockBtn);
  }

  function feltAuction(f) {
    var log = el('div', 'auction-log');
    (snap.auction && snap.auction.log || []).forEach(function (e) {
      var txt = seatName(e.seat) + ': ' + (e.action === 'bid' ? e.amount : 'pass');
      log.appendChild(el('span', e.action === 'bid' ? 'hi' : '', txt));
    });
    f.appendChild(log);

    var high = snap.auction && snap.auction.high;
    f.appendChild(el('p', 'felt-hint', high
      ? (seatName(high.seat) + ' leads the auction at ' + high.amount)
      : 'No bids yet — opening at ' + (snap.auction ? snap.auction.min : snap.players)));

    if (isMe(snap.turnSeat)) {
      var lo = high ? high.amount + 1 : (snap.auction.min || snap.players);
      var hiMax = snap.auction.max || 20;
      var bar = el('div', 'turnbar');
      bar.appendChild(el('div', 'tb-text', 'Your bid'));
      var acts = el('div', 'tb-actions bidbox');
      if (lo <= hiMax) {
        var cur = { v: lo };
        var minus = el('button', 'step', '−');
        var amt = el('span', 'amt', lo);
        var plus = el('button', 'step', '+');
        minus.addEventListener('click', function () { if (cur.v > lo) { cur.v--; amt.textContent = cur.v; } });
        plus.addEventListener('click', function () { if (cur.v < hiMax) { cur.v++; amt.textContent = cur.v; } });
        var bidBtn = el('button', 'act primary', 'Bid');
        bidBtn.addEventListener('click', function () { act(api('bid.php', { game: GAME, action: 'bid', amount: cur.v })); });
        acts.appendChild(minus); acts.appendChild(amt); acts.appendChild(plus); acts.appendChild(bidBtn);
      }
      var pass = el('button', 'act', 'Pass');
      pass.addEventListener('click', function () { act(api('bid.php', { game: GAME, action: 'pass' })); });
      acts.appendChild(pass);
      bar.appendChild(acts);
      f.appendChild(bar);
    }
  }

  function feltDeclare(f) {
    f.appendChild(el('p', 'felt-hint', seatName(snap.bidWinnerSeat) + ' won the bid at ' + snap.winningBid + ' and names in suit.'));
    if (mySeat() === snap.bidWinnerSeat) {
      var bar = el('div', 'turnbar');
      bar.appendChild(el('div', 'tb-text', 'Name in suit'));
      var acts = el('div', 'tb-actions in-suit-box');
      ['S', 'H', 'D', 'C', 'NS'].forEach(function (t) {
        var b = el('button', 'act');
        b.appendChild(el('span', 's' + (RED[t] ? ' red' : ''), SUIT[t]));
        b.addEventListener('click', function () { act(api('declare.php', { game: GAME, inSuit: t })); });
        acts.appendChild(b);
      });
      bar.appendChild(acts);
      f.appendChild(bar);
    }
  }

  function feltDraw(f) {
    meldStrip(f);

    var discarded = snap.discarded || [];
    var need = snap.discardNeeded || [];
    var me = mySeat();
    var meDone = me != null && discarded.indexOf(me) >= 0;

    var dr = el('div', 'ready-row');
    dr.appendChild(el('div', 'ready-count', 'Discarded: ' + discarded.length + ' / ' + need.length));
    dr.appendChild(el('div', 'ready-names', need.map(function (s) {
      return (discarded.indexOf(s) >= 0 ? '✓ ' : '· ') + seatName(s);
    }).join('    ')));
    f.appendChild(dr);

    if (me != null && !meDone) {
      var mySeatInfo = snap.seats[me];
      var maxDiscard = 5 - (snap.keepMin || 0);
      f.appendChild(el('p', 'felt-hint', 'Pick up to ' + maxDiscard + ' card' + (maxDiscard === 1 ? '' : 's') + ' to discard — or stand pat. No need to wait on anyone; replacements are dealt to everyone once the whole table has discarded.'));
      var picked = [];   // tile elements, not codes — the hand can hold duplicates (KS + KS)
      var rowEl = el('div', 'pickrow');
      myHandOrder(mySeatInfo.cards || []).forEach(function (code) {
        var t = cardTile(code, { button: true });
        t.addEventListener('click', function () {
          var i = picked.indexOf(t);
          if (i >= 0) { picked.splice(i, 1); t.classList.remove('picked'); }
          else if (picked.length < maxDiscard) { picked.push(t); t.classList.add('picked'); }
          drawBtn.textContent = picked.length ? ('Discard ' + picked.length) : 'Stand pat';
        });
        rowEl.appendChild(t);
      });
      // dragging reorders the tiles in place and remembers the order — it never
      // touches `picked`, so an in-progress discard selection survives a drag
      enableDragReorder(rowEl, setMyHandOrder);
      f.appendChild(rowEl);
      var bar = el('div', 'turnbar');
      bar.appendChild(el('div', 'tb-text', 'Your discard'));
      var acts = el('div', 'tb-actions');
      var drawBtn = el('button', 'act primary', 'Stand pat');
      drawBtn.addEventListener('click', function () {
        var discards = picked.map(function (t) { return t.dataset.code; });
        act(api('draw.php', { game: GAME, discards: discards }));
      });
      acts.appendChild(drawBtn);
      bar.appendChild(acts);
      f.appendChild(bar);
    } else if (meDone) {
      f.appendChild(el('p', 'lobby-wait', 'You’re done — waiting for the rest of the table.'));
    }
  }

  function feltPlay(f) {
    meldStrip(f);

    var wonBtn = el('button', 'act ghost-btn', showWon ? 'Hide cards won' : 'Review cards won so far');
    wonBtn.addEventListener('click', function () { showWon = !showWon; render(); });
    f.appendChild(wonBtn);
    if (showWon) capturedPanel(f);

    if (isMe(snap.turnSeat) && snap.legalPlays) {
      f.appendChild(el('p', 'felt-hint', 'Your play — the greyed cards aren’t legal (follow / beat / play in suit).'));
      var bar = el('div', 'turnbar');
      bar.appendChild(el('div', 'tb-text', 'Your play'));
      var acts = el('div', 'tb-actions');
      var why = PLAY_REASON[snap.legalPlayReason] || '';
      var best = snap.suggestedPlay;
      var bestWhy = best ? (SUGGEST_REASON[best.reason] || '') : '';
      snap.legalPlays.forEach(function (code, i) {
        var wrap = el('div', 'play-suggestion');
        var b = el('button', 'act' + (i === 0 ? ' primary' : ''));
        b.appendChild(document.createTextNode('Play '));
        b.appendChild(el('span', 's' + (RED[code.charAt(1)] ? ' red' : ''), rankLabel(code.charAt(0)) + SUIT[code.charAt(1)]));
        b.addEventListener('click', function () { doPlay(code); });
        wrap.appendChild(b);
        var badges = el('div', 'play-badges');
        if (best && bestWhy && best.code === code) {
          var star = el('span', 'why-badge star-badge', '⭐');
          star.title = bestWhy;
          star.setAttribute('aria-label', 'Suggested play: ' + bestWhy);
          badges.appendChild(star);
        }
        if (why) {
          var badge = el('span', 'why-badge', '?');
          badge.title = why;
          badge.setAttribute('aria-label', 'Why this play is legal: ' + why);
          badges.appendChild(badge);
        }
        if (badges.childNodes.length) wrap.appendChild(badges);
        acts.appendChild(wrap);
      });
      bar.appendChild(acts);
      f.appendChild(bar);
    }

    var played = el('div', 'played');
    var plays = (snap.trick && snap.trick.plays) || [];
    if (!plays.length && snap.lastTrick) {
      snap.lastTrick.plays.forEach(function (p) {
        played.appendChild(pcard(p, { won: snap.lastTrick.winnerSeat === p.seat }));
      });
      f.appendChild(played);
      f.appendChild(el('div', 'trick-meta', seatName(snap.lastTrick.winnerSeat) + ' took trick ' + snap.tricksPlayed + ' (' + snap.lastTrick.counters + ' ctr)' + (snap.tricksPlayed >= 5 ? ' — hand complete' : ' — leads next')));
    } else {
      plays.forEach(function (p, i) { played.appendChild(pcard(p, { led: i === 0 })); });
      if (plays.length < snap.players) played.appendChild(el('div', 'pcard ghost', ''));
      f.appendChild(played);
    }
  }

  function feltScored(f) {
    meldStrip(f);
    var r = snap.result || {};
    var box = el('div', 'result-box');
    var bidderName = snap.mode === 'teams' ? ('Team ' + (r.biddingSideNo + 1)) : seatName(snap.bidWinnerSeat);
    box.appendChild(el('div', 'big ' + (r.made ? 'made' : 'set'),
      r.made ? (bidderName + ' MADE ' + r.biddingTotal + ' on a bid of ' + r.bid)
             : (bidderName + ' SET −' + r.bid)));
    f.appendChild(box);

    // captured piles — always visible at hand end for verification
    capturedPanel(f);

    if (snap.gameOver) {
      f.appendChild(el('div', 'big made',
        (snap.mode === 'teams' ? 'Team ' + (snap.gameWinner + 1) : seatName(snap.gameWinner)) + ' wins the game!'));
      return;
    }

    var ready = snap.ready || [];
    var need = snap.readyNeeded || [];
    var me = mySeat();
    var meReady = me != null && ready.indexOf(me) >= 0;

    var rr = el('div', 'ready-row');
    rr.appendChild(el('div', 'ready-count', 'Ready: ' + ready.length + ' / ' + need.length));
    rr.appendChild(el('div', 'ready-names', need.map(function (s) {
      return (ready.indexOf(s) >= 0 ? '✓ ' : '· ') + seatName(s);
    }).join('    ')));
    f.appendChild(rr);

    if (!meReady) {
      var rb = el('button', 'act primary', 'Ready for New Hand');
      rb.addEventListener('click', function () { act(api('ready.php', { game: GAME })); });
      f.appendChild(rb);
    } else {
      f.appendChild(el('p', 'lobby-wait', 'You’re ready — waiting for the rest of the table.'));
    }

    var allReady = need.length > 0 && need.every(function (s) { return ready.indexOf(s) >= 0; });
    if (allReady && amHost() && autoDealHandNo !== snap.handNo) {
      autoDealHandNo = snap.handNo;
      act(api('start.php', { game: GAME }));
    }
  }

  function sortCard(a, b) {
    var so = 'SHDC', ro = '9JQKTA';
    var d = so.indexOf(a.charAt(1)) - so.indexOf(b.charAt(1));
    return d || (ro.indexOf(a.charAt(0)) - ro.indexOf(b.charAt(0)));
  }

  function capturedPanel(host) {
    if (!snap.capturedBySide) {
      host.appendChild(el('p', 'lobby-wait', 'No tricks taken yet.'));
      return;
    }
    var mine = (mySeat() != null && snap.seats[mySeat()]) ? snap.seats[mySeat()].sideNo : -1;
    var bidSide = snap.bidWinnerSideNo;
    var wrap = el('div', 'won-panel');
    Object.keys(snap.capturedBySide).forEach(function (k) {
      var side = +k;
      var cards = snap.capturedBySide[k].slice().sort(function (a, b) { return sortCard(a.code, b.code); });
      var grp = el('div', 'won-group' + (side === mine ? ' mine' : ''));

      var head = el('div', 'won-head');
      head.appendChild(el('span', null,
        (snap.mode === 'teams' ? ('Team ' + (side + 1)) : seatName(side)) + (side === bidSide ? ' (bid)' : '')));
      var ctr = (snap.countersBySide && snap.countersBySide[k]) || 0;
      head.appendChild(el('span', 'won-ctr', ctr + ' counter' + (ctr === 1 ? '' : 's')));
      grp.appendChild(head);

      var row = el('div', 'won-cards');
      if (!cards.length) row.appendChild(el('span', 'won-none', 'nothing yet'));
      cards.forEach(function (c) {
        var t = cardTile(c.code, { mini: true });
        if ('ATK'.indexOf(c.code.charAt(0)) >= 0) t.classList.add('counter');
        var playerName = seatName(c.seat);
        t.title = 'Played by ' + playerName;
        t.setAttribute('aria-label', t.getAttribute('aria-label') + ' — played by ' + playerName);
        row.appendChild(t);
      });
      grp.appendChild(row);

      // counters, then meld, then the total  (kept meld + counters captured)
      var meld = (snap.meldBySide && snap.meldBySide[k] != null) ? snap.meldBySide[k] : null;
      var tricks = (snap.tricksWonBySide && snap.tricksWonBySide[k]) || 0;
      var foot = el('div', 'won-foot');
      foot.appendChild(el('span', null, 'counters ' + ctr));
      if (meld != null) {
        var kept = tricks > 0;
        var meldNote = (meld > 0 && !kept) ? (snap.phase === 'scored' ? ' (forfeited — no trick)' : ' (needs a trick)') : '';
        foot.appendChild(el('span', null, 'meld ' + meld + meldNote));
        var total = (kept ? meld : 0) + ctr;
        var tl = el('span', 'won-total', 'total ' + total);
        if (side === bidSide && snap.winningBid) {
          var made = total >= snap.winningBid;
          tl.textContent = 'total ' + total + ' / bid ' + snap.winningBid +
            (snap.phase === 'scored' && !made ? ' — set −' + snap.winningBid : '');
          tl.classList.add(made ? 'made' : 'set');
        }
        foot.appendChild(tl);
      }
      grp.appendChild(foot);
      wrap.appendChild(grp);
    });
    host.appendChild(wrap);
  }

  function pcard(p, opts) {
    opts = opts || {};
    var c = el('div', 'pcard' + (RED[p.card.charAt(1)] ? ' red' : '') +
      (p.card.charAt(0) === 'T' ? ' ten' : '') +
      ('ATK'.indexOf(p.card.charAt(0)) >= 0 ? ' counter' : '') +
      (opts.won ? ' win' : '') + (opts.led ? ' lead' : ''));
    if (opts.won) c.appendChild(el('span', 'flag', 'won'));       // only the card that took the trick
    else if (opts.led) c.appendChild(el('span', 'flag', 'led'));
    c.appendChild(el('span', 'r', rankLabel(p.card.charAt(0))));
    c.appendChild(el('span', 's', SUIT[p.card.charAt(1)]));
    c.appendChild(el('span', 'who', seatName(p.seat)));
    return c;
  }

  function meldStrip(f) {
    if (!snap.meld) return;
    // once any card has hit the table this hand, meld cards collapse by default
    var anyPlayed = (snap.tricksPlayed || 0) > 0 || !!(snap.trick && snap.trick.plays.length);
    var strip = el('div', 'meldstrip');

    Object.keys(snap.meld).forEach(function (seat) {
      var det = snap.meld[seat];
      var s = snap.seats[+seat];
      var sideCls = s ? ' side-' + (s.sideNo % 8) : '';
      var item = el('div', 'meld-item' + (det.total > 0 ? ' feat' : '') + sideCls);

      var head = el('div', 'meld-head');
      head.appendChild(document.createTextNode(seatName(+seat) + ' '));
      head.appendChild(el('span', 'mp', '+' + det.total));
      if (det.items && det.items.length) {
        head.appendChild(el('em', null, det.items.map(function (i) { return i.label; }).join(' · ')));
      }
      item.appendChild(head);

      if (det.items && det.items.length) {
        // How many tiles of each code to draw: group items by (type + cards)
        // signature so genuinely separate physical copies of the same combo
        // (two 9s in suit, two same-suit marriages, a double pinochle) each
        // get their own tile, while a single card reused across two
        // DIFFERENT combos (e.g. Q♠ counted in both a marriage and the
        // pinochle) is only drawn once — the max over every combo touching
        // that code is exactly how many physical copies back it.
        var bySig = {};
        det.items.forEach(function (mi) {
          var sig = mi.type + ':' + (mi.cards || []).slice().sort().join(',');
          bySig[sig] = (bySig[sig] || 0) + 1;
        });
        var need = {};
        det.items.forEach(function (mi) {
          var sig = mi.type + ':' + (mi.cards || []).slice().sort().join(',');
          var n = bySig[sig];
          (mi.cards || []).forEach(function (c) { need[c] = Math.max(need[c] || 0, n); });
        });
        var codes = [];
        Object.keys(need).forEach(function (c) {
          for (var i = 0; i < need[c]; i++) codes.push(c);
        });
        codes.sort(sortCard);

        var cw = el('div', 'meld-cards');
        codes.forEach(function (c) { cw.appendChild(cardTile(c, { mini: true, big: true })); });
        item.appendChild(cw);

        var key = snap.handNo + ':' + seat;
        var open = (key in meldToggle) ? meldToggle[key] : !anyPlayed;
        cw.hidden = !open;
        item.classList.add('meld-clickable');
        item.addEventListener('click', function () {
          var cur = (key in meldToggle) ? meldToggle[key] : !anyPlayed;
          meldToggle[key] = !cur;
          cw.hidden = cur;
        });
      }

      strip.appendChild(item);
    });
    f.appendChild(strip);
  }

  // ---------- actions --------------------------------------------------

  function doPlay(code) { act(api('play.php', { game: GAME, card: code })); }

  function doLeave() {
    if (!GAME) return;
    api('lobby.php?action=leave', { game: GAME }).then(function () {
      setGame(null);
      renderLobby();
    }).catch(function (e) { toast(e.message); });
  }

  function render() {
    if (!snap) return;
    renderStatus();
    renderHands();
    renderScore();
    renderFelt();
    renderMeetButton();
  }

  // ---------- meeting link (host-set video-call button) ----------------

  function renderMeetButton() {
    var btn = document.getElementById('meet-btn');
    var menuItem = document.getElementById('meet-link-item');
    if (menuItem) menuItem.hidden = !amHost();
    if (!btn) return;
    if (snap && snap.meetUrl) { btn.href = snap.meetUrl; btn.hidden = false; }
    else { btn.hidden = true; btn.removeAttribute('href'); }

    // "Remove all bots" lives in the Game menu, not a big clickable button on
    // the felt — a stray click there could void a hand mid-play (misdeal).
    var removeAllItem = document.getElementById('remove-all-bots-item');
    if (removeAllItem) {
      removeAllItem.hidden = !(snap && mySeat() != null &&
        snap.seats.filter(function (s) { return s.isBot; }).length > 0);
    }
  }

  // ---------- chat -----------------------------------------------------

  function addBubble(m) {
    if (m.id && m.id <= chatSince) return;
    if (m.id) chatSince = Math.max(chatSince, m.id);
    var b = document.getElementById('bubbles');
    if (!b) return;
    var isMine = m.uuid === PP.me.uuid;
    var d = el('div', 'bub ' + (isMine ? 'me' : 'them'));
    d.appendChild(el('span', 'nm', m.name));
    d.appendChild(document.createTextNode(m.body));
    b.appendChild(d);
    b.scrollTop = b.scrollHeight;
  }

  var composer = document.getElementById('composer');
  var input = document.getElementById('chatInput');
  if (composer) {
    composer.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (!text) return;
      input.value = '';
      var body = { text: text };
      if (GAME) body.game = GAME;
      api('chat.php', body).then(function (j) { if (j.message) addBubble(j.message); })
        .catch(function (e) { toast(e.message); });
    });
  }

  // ---------- card size + menus (kept from the shell) ---------------

  var sizeButtons = Array.prototype.slice.call(document.querySelectorAll('.cardsize button'));
  function applySize(px, save) {
    root.style.setProperty('--card', px + 'px');
    sizeButtons.forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.size === String(px) ? 'true' : 'false'); });
    if (save) { try { localStorage.setItem('pp-card-size', String(px)); } catch (e) {} }
  }
  var savedSize = 32;
  try { var v = parseInt(localStorage.getItem('pp-card-size'), 10); if (v === 24 || v === 32 || v === 40) savedSize = v; } catch (e) {}
  if (sizeButtons.length) {
    applySize(savedSize, false);
    sizeButtons.forEach(function (b) { b.addEventListener('click', function () { applySize(parseInt(b.dataset.size, 10), true); }); });
  }

  var wraps = Array.prototype.slice.call(document.querySelectorAll('.menu-wrap'));
  function closeMenus(except) {
    wraps.forEach(function (w) {
      if (w === except) return;
      w.querySelector('button').setAttribute('aria-expanded', 'false');
      w.querySelector('.menu-pop').hidden = true;
    });
  }
  wraps.forEach(function (w) {
    var btn = w.querySelector('button');
    var pop = w.querySelector('.menu-pop');
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = btn.getAttribute('aria-expanded') === 'true';
      closeMenus(w);
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
      pop.hidden = open;
    });
    pop.addEventListener('click', function (e) {
      var t = e.target.closest('button'); if (!t) return;
      closeMenus(null);
      var a = t.dataset.act;
      if (a === 'leave-game') doLeave();
      else if (a === 'new-game' || a === 'join-game') { setGame(null); renderLobby(); }
      else if (a === 'how-to-play') { var d = document.getElementById('how-to-dialog'); if (d) d.showModal(); }
      else if (a === 'meet-link') openMeetDialog();
      else if (a === 'remove-all-bots') {
        var mid = snap && snap.phase && snap.phase !== 'scored';
        var msg = 'Remove all bots?' + (mid ? ' A hand is in progress — this will void it as a misdeal.' : '');
        if (window.confirm(msg)) {
          act(api('remove_bot.php', { game: GAME, all: true }));
        }
      }
    });
  });
  document.addEventListener('click', function () { closeMenus(null); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenus(null); });

  var howToDialog = document.getElementById('how-to-dialog');
  if (howToDialog) {
    howToDialog.addEventListener('click', function (e) {
      if (e.target.dataset.act === 'close-how-to' || e.target === howToDialog) howToDialog.close();
    });
  }

  var meetDialog = document.getElementById('meet-dialog');
  var meetForm = document.getElementById('meet-form');
  var meetInput = document.getElementById('meet-url-input');
  function openMeetDialog() {
    if (!meetDialog) return;
    meetInput.value = (snap && snap.meetUrl) || '';
    meetDialog.showModal();
    meetInput.focus();
  }
  function saveMeetUrl(url) {
    if (!GAME) return;
    act(api('meet.php', { game: GAME, url: url }).then(function (j) {
      if (meetDialog) meetDialog.close();
      toast(url ? 'Meeting link saved.' : 'Meeting link cleared.');
      return j;
    }));
  }
  if (meetDialog) {
    meetDialog.addEventListener('click', function (e) {
      if (e.target.dataset.act === 'close-meet' || e.target === meetDialog) meetDialog.close();
      else if (e.target.dataset.act === 'clear-meet') saveMeetUrl('');
    });
  }
  if (meetForm) {
    meetForm.addEventListener('submit', function (e) {
      e.preventDefault();
      saveMeetUrl(meetInput.value.trim());
    });
  }

  // ---------- who's online ---------------------------------------------

  var usersBtn = document.getElementById('users-menu-btn');
  var usersPop = document.getElementById('users-pop');
  var onlineUsers = [];

  function renderUsersPop() {
    if (!usersPop) return;
    clear(usersPop);
    if (!onlineUsers.length) { usersPop.appendChild(el('p', 'users-empty', 'Just you right now.')); return; }
    onlineUsers.forEach(function (u) {
      var mine = u.uuid === PP.me.uuid;
      usersPop.appendChild(el('div', 'user-row' + (mine ? ' me' : ''), u.name + (mine ? ' (you)' : '')));
    });
  }

  function pingPresence() {
    api('presence.php', {}).then(function (j) {
      onlineUsers = j.users || [];
      if (usersBtn && usersBtn.getAttribute('aria-expanded') === 'true') renderUsersPop();
    }).catch(function () {});
  }

  if (usersBtn) {
    // the generic .menu-wrap listener above toggles aria-expanded/hidden first
    // (it was bound earlier, so it always runs before this one); by the time
    // this fires the pop's open/closed state already reflects the click
    usersBtn.addEventListener('click', function () {
      if (usersBtn.getAttribute('aria-expanded') === 'true') { renderUsersPop(); pingPresence(); }
    });
    pingPresence();
    setInterval(pingPresence, 20000);
  }

  // ---------- boot ---------------------------------------------------

  if (GAME) { startPolling(); refresh(); refreshChat(); }
  else { renderLobby(); }
})();
