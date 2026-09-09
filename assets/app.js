/* ===========================================================================
   Poker Pinochle — app shell behaviour
   Card-size preference (persisted), menu bar, and a local chat echo so the
   shell feels alive before the realtime + API layers exist.
   =========================================================================== */
(function () {
  'use strict';

  var root = document.documentElement;
  var toastEl = document.getElementById('toast');
  var toastTimer;

  function toast(msg) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 2400);
  }

  /* ----- card size, persisted per viewer ----- */
  var sizeButtons = Array.prototype.slice.call(document.querySelectorAll('.cardsize button'));
  function applySize(px, save) {
    root.style.setProperty('--card', px + 'px');
    sizeButtons.forEach(function (b) {
      b.setAttribute('aria-pressed', b.dataset.size === String(px) ? 'true' : 'false');
    });
    if (save) {
      try { localStorage.setItem('pp-card-size', String(px)); } catch (e) {}
    }
  }
  var savedSize = 32;
  try {
    var v = parseInt(localStorage.getItem('pp-card-size'), 10);
    if (v === 24 || v === 32 || v === 40) savedSize = v;
  } catch (e) {}
  if (sizeButtons.length) {
    applySize(savedSize, false);
    sizeButtons.forEach(function (b) {
      b.addEventListener('click', function () { applySize(parseInt(b.dataset.size, 10), true); });
    });
  }

  /* ----- menu bar ----- */
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
      var t = e.target.closest('button');
      if (!t) return;
      toast('Not wired up yet — "' + t.textContent.replace(/[…–].*$/, '').trim() + '"');
      closeMenus(null);
    });
  });
  document.addEventListener('click', function () { closeMenus(null); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenus(null); });

  /* ----- placeholder action buttons ----- */
  Array.prototype.slice.call(document.querySelectorAll('.act, .lobby button')).forEach(function (b) {
    b.addEventListener('click', function () {
      toast('Not wired up yet — the engine and API come next.');
    });
  });

  /* ----- chat: local echo until chat.php + Soketi land ----- */
  var composer = document.getElementById('composer');
  var input = document.getElementById('chatInput');
  var bubbles = document.getElementById('bubbles');
  if (composer && input && bubbles) {
    composer.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (!text) return;
      var b = document.createElement('div');
      b.className = 'bub me';
      var nm = document.createElement('span');
      nm.className = 'nm';
      nm.textContent = (bubbles.dataset.me || 'You');
      b.appendChild(nm);
      b.appendChild(document.createTextNode(text));
      bubbles.appendChild(b);
      bubbles.scrollTop = bubbles.scrollHeight;
      input.value = '';
    });
    bubbles.scrollTop = bubbles.scrollHeight;
  }
})();
