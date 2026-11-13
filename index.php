<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// Poker Pinochle — front controller.
// PIN gate + the left / center / right app shell. No game logic yet: the
// engine, persistence, HTTP API and realtime layers land on top of this.
// -----------------------------------------------------------------------------

require __DIR__ . '/lib/bootstrap.php';

$cfg  = pp_config();
$base = (string)($cfg['cookie_path'] ?? '/');
$err  = '';

// --- logout ---------------------------------------------------------------
if (isset($_GET['logout'])) {
    pp_logout();
    header('Location: ' . $base);
    exit;
}

// --- login submit -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $name = trim((string)($_POST['username'] ?? ''));
    $pin  = (string)($_POST['pin'] ?? '');

    if (!pp_csrf_check($_POST['csrf'] ?? null)) {
        $err = 'Your session expired — please try again.';
    } elseif (!pp_valid_name($name)) {
        $err = 'Pick a name 2–24 characters long: letters, numbers, spaces, - _ \' only.';
    } elseif (!preg_match('/^\d{4,8}$/', $pin) || !hash_equals((string)$cfg['pin'], $pin)) {
        $err = 'That PIN is not right.';
    } else {
        pp_login($name);
        header('Location: ' . $base);   // POST/redirect/GET
        exit;
    }
}

$user = pp_current_user();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Poker Pinochle</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap">
<link rel="stylesheet" href="assets/app.css?v=9">
</head>
<body>

<?php if ($user === null): ?>

  <div class="login-wrap">
    <form class="login" method="post" action="<?= h($base) ?>" autocomplete="off">
      <h1><span class="pip" aria-hidden="true">&#9829;&#9830;</span> Poker&nbsp;Pinochle <span class="pip" aria-hidden="true">&#9824;&#9827;</span></h1>
      <p class="sub">Enter the table PIN and pick a name to sit down.</p>

      <?php if ($err !== ''): ?>
        <div class="err" role="alert"><?= h($err) ?></div>
      <?php endif; ?>

      <input type="hidden" name="action" value="login">
      <input type="hidden" name="csrf" value="<?= h(pp_csrf_token()) ?>">

      <div class="field">
        <label for="username">Your name</label>
        <input id="username" name="username" type="text" maxlength="24"
               autocomplete="nickname" autofocus required
               value="<?= h((string)($_POST['username'] ?? '')) ?>">
      </div>

      <div class="field">
        <label for="pin">Table PIN</label>
        <input id="pin" name="pin" type="password" inputmode="numeric"
               pattern="\d{4,8}" maxlength="8" autocomplete="off" required>
      </div>

      <button type="submit">Sit down</button>
    </form>
  </div>

<?php else: ?>

  <div class="app">
    <header class="menubar">
      <div class="wordmark"><span class="pip" aria-hidden="true">&#9829;&#9830;</span> Poker&nbsp;Pinochle <span class="pip" aria-hidden="true">&#9824;&#9827;</span></div>

      <nav class="menus" aria-label="Main menu">
        <div class="menu-wrap">
          <button type="button" aria-haspopup="true" aria-expanded="false">Game</button>
          <div class="menu-pop" hidden>
            <button type="button" data-act="new-game">New game&hellip;</button>
            <button type="button" data-act="join-game">Join by code&hellip;</button>
            <button type="button" data-act="leave-game">Leave table</button>
            <button type="button" data-act="meet-link" id="meet-link-item" hidden>Meeting link&hellip;</button>
            <button type="button" data-act="remove-all-bots" id="remove-all-bots-item" hidden>Remove all bots</button>
            <hr>
            <button type="button" onclick="location.href='?logout'">Sign out</button>
          </div>
        </div>
        <div class="menu-wrap">
          <button type="button" id="users-menu-btn" aria-haspopup="true" aria-expanded="false">Users</button>
          <div class="menu-pop users-pop" id="users-pop" hidden>
            <p class="users-empty">Loading&hellip;</p>
          </div>
        </div>
        <div class="menu-wrap">
          <button type="button" aria-haspopup="true" aria-expanded="false">Help</button>
          <div class="menu-pop" hidden>
            <button type="button" data-act="how-to-play">How to play</button>
          </div>
        </div>
      </nav>

      <a class="meet-btn" id="meet-btn" href="#" target="_blank" rel="noopener" hidden>
        <span aria-hidden="true">&#128247;</span> Join video call
      </a>

      <div class="userbox">
        Signed in as <b><?= h($user['name']) ?></b>
        <a href="?logout">Sign out</a>
      </div>

      <div class="cardsize" role="group" aria-label="Card size">
        <span class="cardsize-label">Card size</span>
        <button type="button" data-size="24" aria-pressed="false">24</button>
        <button type="button" data-size="32" aria-pressed="true">32</button>
        <button type="button" data-size="40" aria-pressed="false">40</button>
      </div>
    </header>

    <aside class="rail">
      <h2 class="rail-h">Hands</h2>
      <section class="hands" id="hands" aria-label="Players and hands">
        <p class="empty">Not at a table yet.</p>
      </section>

      <h2 class="rail-h">Chat</h2>
      <section class="chat" aria-label="Table chat">
        <div class="bubbles" id="bubbles" data-me="<?= h($user['name']) ?>"></div>
      </section>
      <form class="composer" id="composer">
        <input type="text" id="chatInput" placeholder="Message the table&hellip;" aria-label="Chat message" autocomplete="off">
        <button type="submit">Send</button>
      </form>
    </aside>

    <main class="table" aria-label="The table">
      <div class="status" id="status" hidden>
        <div class="status-main" id="status-main"></div>
        <div class="status-turn" id="status-turn"></div>
      </div>
      <div class="felt" id="felt"></div>
    </main>

    <aside class="scorepanel" aria-label="Running score">
      <h2 class="rail-h">Score</h2>
      <div class="sp-body" id="score"><p class="empty">No game yet.</p></div>
    </aside>
  </div>

  <div class="toast" id="toast" role="status" aria-live="polite"></div>

  <dialog class="how-to-dialog" id="how-to-dialog">
    <h2>How to play Poker Pinochle</h2>
    <ul>
      <li>Poker Pinochle is played with a pinochle deck. If you're unfamiliar with it: a pinochle deck has two of every card, but only holds cards in the four suits from 9 up to Ace — no 2 through 8. And the key feature is the ten, which has more power than any face card (but not the ace). So strength runs Ace, then 10, then King, Queen, Jack, and 9 is weakest. Since every card is doubled, you'll run into exact ties a lot — whoever played their copy first wins the tie.</li>
      <li>Each player gets a 5-card hand.</li>
      <li>One trip around the table for the auction — highest bidder names in suit.</li>
      <li>In Suit is a chosen suit that beats every card of every other suit. Once a trick can no longer be won by following suit, playing an in-suit card is the only way to take it — so naming it lets the bidder turn their strongest suit into a weapon, and everyone else's hand has to reckon with it on every trick.</li>
      <li>Every player then discards down — everyone at once, no waiting your turn — and, once the <em>whole table</em> has discarded, draws back up to 5 cards from a shared stock built from what's left of the single 48-card deck after the deal. Nobody's replacements are dealt until everyone has pitched — the discard round finishes first, then the re-deal happens for every seat at once, so no seat's redraw odds depend on drawing before or after anyone else. With 5+ players there aren't enough spare cards for everyone to redraw a full hand, so you're required to keep a minimum from your original 5:
        <table class="how-to-table">
          <thead><tr><th>Players</th><th>Must keep</th><th>Max discard</th></tr></thead>
          <tbody>
            <tr><td>2&ndash;4</td><td>0</td><td>5 (whole hand)</td></tr>
            <tr><td>5</td><td>1</td><td>4</td></tr>
            <tr><td>6&ndash;8</td><td>2</td><td>3</td></tr>
          </tbody>
        </table>
        At 2&ndash;6 players those minimums guarantee the stock can't run out. At 7&ndash;8 players it still can — and often will, since even everyone playing to the cap can ask for more redraws than are left in the stock. When that happens, already-discarded cards are reshuffled back in as the new stock — there's still only ever one deck, never a second one.
      </li>
      <li>Meld is auto-shown right at the start of play, from your final 5-card hand — nothing to declare by hand. It counts toward your score, but a card can still be led or played normally; melding never removes it from your hand. You only <em>keep</em> your meld points if your side takes at least one trick that hand — take none, and they're forfeited.
        <ul>
          <li>Marriage (K+Q of the same suit) — 2 points, or 4 if it's a marriage in suit ("royal marriage").</li>
          <li>The 9 in suit — 1 point. Doesn't exist when there's no suit in play (the "N" contract).</li>
          <li>Pinochle (Q♠ + J♦) — 4 points.</li>
          <li>Four Jacks (one of each suit) — 4 points.</li>
          <li>Four Queens (one of each suit) — 6 points.</li>
          <li>Four Kings (one of each suit) — 8 points.</li>
          <li>Four Aces (one of each suit) — 10 points.</li>
        </ul>
        Because the deck has two of every card, you can hold a combo twice over (two marriages in the same suit, a double pinochle, both 9s in suit) — each full copy scores again.
      </li>
      <li>5 tricks are played. Each trick, in order, you must play the strongest legal card you have — there's no "ducking" to save a good card:
        <ul>
          <li>If you hold the led suit, you must play it — and if one of your cards in that suit beats every card of that suit played so far, you must play one of those beaters, not a low one.</li>
          <li>If you're out of the led suit and there's a suit in play, you must play in suit — and if in suit has already been played to the trick, you must beat it with a higher one if you're able to.</li>
          <li>Only when you can't follow suit and can't play in suit (or the contract is "N", no suit in play at all) are you free to throw away any card.</li>
        </ul>
        This is called "all-beat": whoever can beat the trick so far is forced to. Whoever plays the highest card in suit wins the trick — or, if no one played in suit, whoever played the highest card of the led suit. Since the deck holds two of everything, exact ties are possible; the tie goes to whichever copy hit the table first.
      </li>
    </ul>
    <button type="button" data-act="close-how-to" class="act primary">Got it</button>
  </dialog>

  <dialog class="meet-dialog" id="meet-dialog">
    <h2>Meeting link</h2>
    <p class="meet-dialog-hint">Paste a Google Meet (or other video-call) link. Every player at
      this table will see a camera button to join it.</p>
    <form id="meet-form">
      <label for="meet-url-input">Meeting URL</label>
      <input type="url" id="meet-url-input" placeholder="https://meet.google.com/xxx-xxxx-xxx" maxlength="500">
      <div class="meet-dialog-actions">
        <button type="button" data-act="clear-meet" class="act ghost-btn">Clear</button>
        <button type="button" data-act="close-meet" class="act ghost-btn">Cancel</button>
        <button type="submit" class="act primary">Save</button>
      </div>
    </form>
  </dialog>
  <script>
    window.PP = {
      me:    { uuid: <?= json_encode($user['uuid']) ?>, name: <?= json_encode($user['name']) ?> },
      csrf:  <?= json_encode(pp_csrf_token()) ?>,
      base:  <?= json_encode($base) ?>,
      wsUrl: <?= json_encode((string) ($cfg['soketi']['ws_url'] ?? '')) ?>,
      wsKey: <?= json_encode((string) ($cfg['soketi']['key'] ?? '')) ?>
    };
  </script>
  <script src="assets/app.js?v=18"></script>

<?php endif; ?>

</body>
</html>
