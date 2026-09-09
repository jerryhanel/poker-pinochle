<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// -----------------------------------------------------------------------------
// pp_db() — one shared mysqli handle for the poker_pinochle database.
// Credentials come from config.php ['db']. MySQL is on the NAS (192.168.8.218),
// reached over the LAN — not localhost.
// -----------------------------------------------------------------------------

function pp_db(): mysqli
{
    static $db = null;
    if ($db instanceof mysqli) {
        return $db;
    }

    $c = pp_config()['db'] ?? [];
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $db = new mysqli(
        (string) ($c['host'] ?? '192.168.8.218'),
        (string) ($c['user'] ?? ''),
        (string) ($c['pass'] ?? ''),
        (string) ($c['name'] ?? '')
    );
    $db->set_charset('utf8mb4');
    return $db;
}

/**
 * Run $fn inside a transaction, committing on return and rolling back on any
 * throwable. $fn receives the mysqli handle.
 * @return mixed whatever $fn returns
 */
function pp_tx(callable $fn)
{
    $db = pp_db();
    $db->begin_transaction();
    try {
        $out = $fn($db);
        $db->commit();
        return $out;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
