<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// dev.php — browser runner for the engine harness. Dev tooling only.
// Requires a signed-in session (the shared PIN). Prints plain text.
//
//   /poker-pinochle/dev.php            tests + sample hand
//   /poker-pinochle/dev.php?quiet=1    tests only
//   /poker-pinochle/dev.php?hand=1     sample hand only
// -----------------------------------------------------------------------------

require __DIR__ . '/lib/bootstrap.php';

if (pp_current_user() === null) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Sign in at /poker-pinochle/ first (this page is PIN-gated dev tooling).\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "Poker Pinochle — engine harness\nPHP " . PHP_VERSION . "\n";

// harness.php reads $argv; synthesise it for the web SAPI.
$argv = ['harness'];
if (isset($_GET['quiet'])) { $argv[] = '--quiet'; }
if (isset($_GET['hand']))  { $argv[] = '--hand'; }
$argc = count($argv);

require __DIR__ . '/engine/harness.php';
