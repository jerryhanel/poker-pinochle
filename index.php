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
          <button type="button" aria-haspopup="true" aria-expanded="false">Game</button>
          <div class="menu-pop" hidden>
            <button type="button" data-act="new-game">New game&hellip;</button>
            <button type="button" data-act="join-game">Join by code&hellip;</button>
            <button type="button" data-act="leave-game">Leave table</button>
            <hr>
            <button type="button" onclick="location.href='?logout'">Sign out</button>
          </div>
        </div>
        <div class="menu-wrap">
          <button type="button" aria-haspopup="true" aria-expanded="false">Help</button>
          <div class="menu-pop" hidden>
            <button type="button" data-act="how-to-play">How to play</button>
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
  <script>
    window.PP = {
      me:    { uuid: <?= json_encode($user['uuid']) ?>, name: <?= json_encode($user['name']) ?> },
      csrf:  <?= json_encode(pp_csrf_token()) ?>,
      base:  <?= json_encode($base) ?>,
      wsUrl: <?= json_encode((string) ($cfg['soketi']['ws_url'] ?? '')) ?>,
      wsKey: <?= json_encode((string) ($cfg['soketi']['key'] ?? '')) ?>
    };
  </script>
  <script src="assets/app.js?v=2"></script>

<?php endif; ?>

</body>
</html>
