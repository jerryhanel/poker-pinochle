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
<link rel="stylesheet" href="assets/app.css?v=1">
</head>
<body>

<?php if ($user === null): ?>

  <div class="login-wrap">
    <form class="login" method="post" action="<?= h($base) ?>" autocomplete="off">
      <h1>Poker&nbsp;Pinochle <span class="pip" aria-hidden="true">&#9824;&#9827;</span></h1>
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
      <div class="wordmark">Poker&nbsp;Pinochle <span class="pip" aria-hidden="true">&#9824;&#9827;</span></div>

      <nav class="menus" aria-label="Main menu">
        <div class="menu-wrap">
          <button type="button" aria-haspopup="true" aria-expanded="false">File</button>
          <div class="menu-pop" hidden>
            <button type="button">New game&hellip;</button>
            <button type="button">Join game by code&hellip;</button>
            <button type="button">Leave table</button>
            <hr>
            <button type="button" onclick="location.href='?logout'">Sign out</button>
          </div>
        </div>
        <div class="menu-wrap">
          <button type="button" aria-haspopup="true" aria-expanded="false">Help</button>
          <div class="menu-pop" hidden>
            <button type="button">How to play</button>
            <button type="button">Meld &amp; scoring reference</button>
            <hr>
            <button type="button">About this table</button>
          </div>
        </div>
      </nav>

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

    <!-- LEFT: hands + chat -->
    <aside class="rail">
      <h2 class="rail-h">Hands</h2>
      <section class="hands" aria-label="Players and hands">
        <div class="team-tab t1">Table <span class="team-score">1</span></div>
        <div class="player active">
          <div class="p-name">
            <?= h($user['name']) ?> <span class="badge you">you</span>
          </div>
          <div class="p-cards" aria-label="Your hand"><span class="count" style="margin-left:0">no cards yet</span></div>
        </div>
        <p class="empty">Waiting for others to sit down. When everyone&rsquo;s ready you&rsquo;ll pick teams (or play cutthroat on an odd count).</p>
      </section>

      <h2 class="rail-h">Chat</h2>
      <section class="chat" aria-label="Table chat">
        <div class="bubbles" id="bubbles" data-me="<?= h($user['name']) ?>">
          <div class="bub sys"><?= h($user['name']) ?> sat down</div>
        </div>
      </section>
      <form class="composer" id="composer">
        <input type="text" id="chatInput" placeholder="Message the table&hellip;" aria-label="Chat message" autocomplete="off">
        <button type="submit">Send</button>
      </form>
    </aside>

    <!-- CENTER: the table -->
    <main class="table" aria-label="The table">
      <div class="status">
        <div class="status-main">
          <span>No game running</span>
          <span class="mock">Shell &middot; login + layout</span>
        </div>
        <div class="status-turn">Signed in. The engine, API and realtime layers come next.</div>
      </div>

      <div class="felt">
        <div class="lobby">
          <h2>You&rsquo;re at the table, <?= h($user['name']) ?>.</h2>
          <p>No hand is in progress yet. Start a game or join one with a share code — cards, the auction, meld and scoring aren&rsquo;t built yet.</p>
          <div class="row">
            <button class="act primary" type="button">New game</button>
            <button class="act ghost-btn" type="button">Join by code</button>
          </div>
        </div>
      </div>
    </main>

    <!-- RIGHT: full-height score pad -->
    <aside class="scorepanel" aria-label="Running score">
      <h2 class="rail-h">Score</h2>
      <div class="sp-body">
        <div class="bid-card">
          <span class="k">This hand &mdash; the bid</span>
          <span class="v"><span class="who">&mdash;</span></span>
          <span class="sub">no bid yet</span>
        </div>
        <p class="empty">No hands played yet. Each hand&rsquo;s meld, counters and running total will land here.</p>
        <div class="sp-goal">Game target set when a game starts</div>
      </div>
    </aside>
  </div>

  <div class="toast" id="toast" role="status" aria-live="polite"></div>
  <script src="assets/app.js?v=1"></script>

<?php endif; ?>

</body>
</html>
