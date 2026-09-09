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
  var SUIT = { S: '♠', H: '♥', D: '♦', C: '♣', NT: 'NT' };
  var RED = { H: 1, D: 1 };
  var PHASE_LABEL = { auction: 'Auction', declare: 'Naming trump', draw: 'The draw', play: 'Playing', scored: 'Hand over' };

  var snap = null;          // last game snapshot
  var GAME = null;          // current game uuid
  var chatSince = 0;
  var pollTimer = null;
  var busy = false;         // an action POST is in flight
  var showWon = false;      // "cards won so far" panel toggled open

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
    if (opts.down) { b.classList.add('down'); return b; }
    b.appendChild(el('span', 'r', code.charAt(0)));
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
      s.handNo, s.phase, s.turnSeat, s.gameOver, s.winningBid, s.trump,
      (s.trick && s.trick.plays.length) || 0,
      (s.auction && s.auction.log.length) || 0,
      (s.journal && s.journal.length) || 0,
      (s.ready && s.ready.length) || 0,
      s.seats.filter(function (x) { return x.uuid; }).length
    ].join('|');
  }

  function refresh() {
    if (!GAME) { renderLobby(); return Promise.resolve(); }
    return api('game.php?g=' + encodeURIComponent(GAME)).then(function (j) {
      var changed = sig(j) !== sig(snap);
      snap = j;
      if (changed) render();   // else: nothing moved — keep any in-progress input
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

  function act(promise) {
    busy = true;
    return promise.then(function (j) {
      if (j && j.phase) { snap = j; render(); }
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

    var list = el('div', 'lobby-list');
    box.appendChild(list);
    api('lobby.php?action=list').then(function (j) {
      if (!j.games || !j.games.length) { list.appendChild(el('p', 'empty', 'No open games right now.')); return; }
      j.games.forEach(function (g) {
        var r = el('button', 'lobby-open', g.mode + ' · ' + g.seated + '/' + g.playerCount + ' seated · code ' + g.joinCode);
        r.addEventListener('click', function () {
          api('lobby.php?action=join', { game: g.gameUuid }).then(function (jj) {
            setGame(jj.gameUuid); startPolling(); refresh();
          }).catch(function (e) { toast(e.message, 3500); });
        });
        list.appendChild(r);
      });
    }).catch(function () {});

    f.appendChild(box);
  }

  // ---------- table: hands panel --------------------------------------

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
      var tab = el('div', 'team-tab ' + (g.sideNo === 0 ? 't1' : 't2'));
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
        if (s.isDealer) nm.appendChild(el('span', 'badge deal', 'dealer'));
        if (snap.bidWinnerSeat === s.seat && snap.winningBid) nm.appendChild(el('span', 'badge bid', 'bid ' + snap.winningBid));
        if (snap.turnSeat === s.seat) nm.appendChild(el('span', 'badge turn', 'turn'));
        row.appendChild(nm);

        var cards = el('div', 'p-cards' + (s.cards ? '' : ' down'));
        if (s.cards) {
          var legal = (snap.phase === 'play' && snap.turnSeat === s.seat && snap.legalPlays) ? snap.legalPlays : null;
          s.cards.forEach(function (code) {
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
        } else if (s.count > 0) {
          for (var i = 0; i < s.count; i++) cards.appendChild(cardTile('XX', { down: true, mini: true }));
          cards.appendChild(el('span', 'count', s.count));
        } else {
          cards.appendChild(el('span', 'count', '—'));
        }
        row.appendChild(cards);
        host.appendChild(row);
      });
    });
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
      if (snap.trump) v.appendChild(el('span', 'suit' + (RED[snap.trump] ? ' red' : ''), SUIT[snap.trump]));
    } else {
      v.appendChild(el('span', 'who', '—'));
    }
    bc.appendChild(v);
    if (snap.meldBySide) {
      bc.appendChild(el('span', 'sub', 'meld  ' + sideList(snap.meldBySide)));
    }
    host.appendChild(bc);

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

    // journal
    if (snap.journal && snap.journal.length) {
      var wrap = el('div', 'journal-wrap');
      var t = el('table', 'journal');
      var cap = el('caption', null, 'Hand-by-hand'); t.appendChild(cap);
      var thead = el('thead'); var htr = el('tr');
      htr.appendChild(el('th', null, 'Hand'));
      sides.forEach(function (k) { htr.appendChild(el('th', null, snap.mode === 'teams' ? ('T' + (+k + 1)) : seatName(+k))); });
      thead.appendChild(htr); t.appendChild(thead);
      var tb = el('tbody');
      snap.journal.forEach(function (h) {
        var tr = el('tr');
        tr.appendChild(el('td', null, h.handNo + (h.trump ? ' ' + SUIT[h.trump] : '')));
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
    if (snap.trump) m.appendChild(el('span', null, 'Trump ' + SUIT[snap.trump]));
    if (snap.winningBid) m.appendChild(el('span', null, seatName(snap.bidWinnerSeat) + ' bid ' + snap.winningBid));
    if (snap.phase === 'play') m.appendChild(el('span', null, 'Trick ' + ((snap.tricksPlayed || 0) + 1) + ' of 5'));

    var tn = document.getElementById('status-turn');
    if (snap.gameOver) tn.textContent = (snap.mode === 'teams' ? 'Team ' + (snap.gameWinner + 1) : seatName(snap.gameWinner)) + ' wins the game!';
    else if (snap.phase === 'scored') tn.textContent = 'Hand scored.';
    else if (snap.phase === 'lobby' || !snap.phase) tn.textContent = 'Waiting to start.';
    else if (snap.turnSeat != null) tn.textContent = isMe(snap.turnSeat) ? 'Your turn.' : (seatName(snap.turnSeat) + '’s turn.');
    else tn.textContent = '';
  }

  function renderFelt() {
    var f = document.getElementById('felt');
    clear(f);
    var ph = snap.phase;

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
    if (amHost() && seated >= snap.players) {
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
    f.appendChild(el('p', 'felt-hint', seatName(snap.bidWinnerSeat) + ' won the bid at ' + snap.winningBid + ' and names trump.'));
    if (mySeat() === snap.bidWinnerSeat) {
      var bar = el('div', 'turnbar');
      bar.appendChild(el('div', 'tb-text', 'Name trump'));
      var acts = el('div', 'tb-actions trumpbox');
      ['S', 'H', 'D', 'C', 'NT'].forEach(function (t) {
        var b = el('button', 'act' + (t === 'S' ? ' primary' : ''));
        b.appendChild(el('span', 's' + (RED[t] ? ' red' : ''), SUIT[t]));
        b.addEventListener('click', function () { act(api('declare.php', { game: GAME, trump: t })); });
        acts.appendChild(b);
      });
      bar.appendChild(acts);
      f.appendChild(bar);
    }
  }

  function feltDraw(f) {
    meldStrip(f);
    if (isMe(snap.turnSeat)) {
      var me = snap.seats[mySeat()];
      var maxDiscard = 5 - (snap.keepMin || 0);
      f.appendChild(el('p', 'felt-hint', 'Pick up to ' + maxDiscard + ' card' + (maxDiscard === 1 ? '' : 's') + ' to discard, then draw — or stand pat.'));
      var picked = [];
      var rowEl = el('div', 'pickrow');
      (me.cards || []).forEach(function (code) {
        var t = cardTile(code, { button: true });
        t.addEventListener('click', function () {
          var i = picked.indexOf(code);
          if (i >= 0) { picked.splice(i, 1); t.classList.remove('picked'); }
          else if (picked.length < maxDiscard) { picked.push(code); t.classList.add('picked'); }
          drawBtn.textContent = picked.length ? ('Discard ' + picked.length + ' & draw') : 'Stand pat';
        });
        rowEl.appendChild(t);
      });
      f.appendChild(rowEl);
      var bar = el('div', 'turnbar');
      bar.appendChild(el('div', 'tb-text', 'Your draw'));
      var acts = el('div', 'tb-actions');
      var drawBtn = el('button', 'act primary', 'Stand pat');
      drawBtn.addEventListener('click', function () { act(api('draw.php', { game: GAME, discards: picked })); });
      acts.appendChild(drawBtn);
      bar.appendChild(acts);
      f.appendChild(bar);
    } else {
      f.appendChild(el('p', 'felt-hint', 'Waiting for ' + seatName(snap.turnSeat) + ' to draw…'));
    }
  }

  function feltPlay(f) {
    meldStrip(f);

    var wonBtn = el('button', 'act ghost-btn', showWon ? 'Hide cards won' : 'Review cards won so far');
    wonBtn.addEventListener('click', function () { showWon = !showWon; render(); });
    f.appendChild(wonBtn);
    if (showWon) capturedPanel(f);

    var played = el('div', 'played');
    var plays = (snap.trick && snap.trick.plays) || [];
    if (!plays.length && snap.lastTrick) {
      snap.lastTrick.plays.forEach(function (p) { played.appendChild(pcard(p, snap.lastTrick.winnerSeat === p.seat, 'won')); });
      f.appendChild(played);
      f.appendChild(el('div', 'trick-meta', seatName(snap.lastTrick.winnerSeat) + ' took trick ' + snap.tricksPlayed + ' (' + snap.lastTrick.counters + ' ctr) — leads next'));
    } else {
      plays.forEach(function (p) { played.appendChild(pcard(p, false, p === plays[0] ? 'led' : '')); });
      if (plays.length < snap.players) played.appendChild(el('div', 'pcard ghost', ''));
      f.appendChild(played);
      f.appendChild(el('div', 'trick-meta', 'Trick ' + ((snap.tricksPlayed || 0) + 1) + ' · ' + counterSummary(snap.tricksWonBySide)));
    }

    if (isMe(snap.turnSeat) && snap.legalPlays) {
      f.appendChild(el('p', 'felt-hint', 'Your play — the greyed cards aren’t legal (follow / beat / trump).'));
      var bar = el('div', 'turnbar');
      bar.appendChild(el('div', 'tb-text', 'Your play'));
      var acts = el('div', 'tb-actions');
      snap.legalPlays.forEach(function (code, i) {
        var b = el('button', 'act' + (i === 0 ? ' primary' : ''));
        b.appendChild(document.createTextNode('Play '));
        b.appendChild(el('span', 's' + (RED[code.charAt(1)] ? ' red' : ''), code.charAt(0) + SUIT[code.charAt(1)]));
        b.addEventListener('click', function () { doPlay(code); });
        acts.appendChild(b);
      });
      bar.appendChild(acts);
      f.appendChild(bar);
    }
  }

  function feltScored(f) {
    meldStrip(f);
    var r = snap.result || {};
    var box = el('div', 'result-box');
    var bidderName = snap.mode === 'teams' ? ('Team ' + (r.biddingSideNo + 1)) : seatName(snap.bidWinnerSeat);
    box.appendChild(el('div', 'big ' + (r.made ? 'made' : 'set'),
      r.made ? (bidderName + ' MADE ' + r.biddingTotal) : (bidderName + ' SET −' + r.bid)));
    box.appendChild(el('p', 'felt-hint',
      'bid ' + r.bid + ' · meld ' + sideList(snap.meldBySide || {}) + ' · counters ' + sideList(snap.countersBySide || {})));
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

    if (amHost()) {
      var allReady = need.length > 0 && need.every(function (s) { return ready.indexOf(s) >= 0; });
      var db = el('button', 'act' + (allReady ? ' primary' : ''), 'Deal the next hand');
      db.disabled = !allReady;
      db.addEventListener('click', function () { act(api('start.php', { game: GAME })); });
      f.appendChild(db);
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
    var wrap = el('div', 'won-panel');
    Object.keys(snap.capturedBySide).forEach(function (k) {
      var cards = snap.capturedBySide[k].slice().sort(sortCard);
      var grp = el('div', 'won-group' + (+k === mine ? ' mine' : ''));
      var head = el('div', 'won-head');
      head.appendChild(el('span', null, snap.mode === 'teams' ? ('Team ' + (+k + 1)) : seatName(+k)));
      var ctr = (snap.countersBySide && snap.countersBySide[k]) || 0;
      head.appendChild(el('span', 'won-ctr', ctr + ' counter' + (ctr === 1 ? '' : 's')));
      grp.appendChild(head);
      var row = el('div', 'won-cards');
      if (!cards.length) row.appendChild(el('span', 'won-none', 'nothing yet'));
      cards.forEach(function (c) {
        var t = cardTile(c, { mini: true });
        if ('ATK'.indexOf(c.charAt(0)) >= 0) t.classList.add('counter');
        row.appendChild(t);
      });
      grp.appendChild(row);
      wrap.appendChild(grp);
    });
    host.appendChild(wrap);
  }

  function pcard(p, win, flag) {
    var c = el('div', 'pcard' + (RED[p.card.charAt(1)] ? ' red' : '') + (flag === 'led' ? ' lead' : ''));
    if (flag) c.appendChild(el('span', 'flag', flag === 'won' ? 'won' : 'led'));
    c.appendChild(el('span', 'r', p.card.charAt(0)));
    c.appendChild(el('span', 's', SUIT[p.card.charAt(1)]));
    c.appendChild(el('span', 'who', seatName(p.seat)));
    if (win) c.classList.add('lead');
    return c;
  }

  function meldStrip(f) {
    if (!snap.meld) return;
    var strip = el('div', 'meldstrip');
    Object.keys(snap.meld).forEach(function (seat) {
      var det = snap.meld[seat];
      var item = el('div', 'meld-item' + (det.total > 0 ? ' feat' : ''));
      item.appendChild(document.createTextNode(seatName(+seat) + ' '));
      item.appendChild(el('span', 'mp', '+' + det.total));
      if (det.items && det.items.length) {
        item.appendChild(el('em', null, det.items.map(function (i) { return i.label; }).join(' · ')));
      }
      strip.appendChild(item);
    });
    f.appendChild(strip);
  }

  function counterSummary(map) {
    if (!map) return '';
    return Object.keys(map).map(function (k) {
      return (snap.mode === 'teams' ? 'T' + (+k + 1) : ('#' + k)) + ' ' + map[k] + ' ctr';
    }).join(' · ');
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
      else if (a === 'how-to-play') toast('Rules: 5-card hands, one-trip auction, discard-and-draw, meld counts toward the bid, 5 tricks, all-beat.', 6000);
    });
  });
  document.addEventListener('click', function () { closeMenus(null); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenus(null); });

  // ---------- boot ---------------------------------------------------

  if (GAME) { startPolling(); refresh(); refreshChat(); }
  else { renderLobby(); }
})();
