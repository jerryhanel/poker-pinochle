<?php
declare(strict_types=1);

require_once __DIR__ . '/../../engine/autoload.php';
require_once __DIR__ . '/../Assert.php';

// =============================================================================
// Api — placeholder, 0 assertions (UPGRADE.md step 3.11, deferred).
//
// Why skipped:
//   - Every Api action (lobbyCreate, lobbyJoin, lobbyList, bid, play, game,
//     presencePing, ...) runs inside pp_tx(), which calls pp_db() and opens a
//     real mysqli connection from config.php. There is no seam to hand it a
//     FakeMysqli.
//   - Api's dependencies (GameStore, Presence, BotPlayer) are all `final`
//     classes and Api type-hints the concrete classes, so they cannot be
//     replaced by anonymous-class stubs either.
//
// To make this testable, Api would need the transaction runner (or a mysqli /
// a "Db" interface) injected, and its dependencies typed against interfaces.
// Until then Api is covered only by manual / integration testing.
// =============================================================================
