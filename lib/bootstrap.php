<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// Poker Pinochle — request bootstrap.
// Loads config, starts the session, and defines the small helper surface every
// entry point uses. No DB here yet (login is PIN-only; the game tables come
// online with the engine / persistence layer).
// -----------------------------------------------------------------------------

const PP_ROOT = __DIR__ . '/..';

/** @return array<string,mixed> */
function pp_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = PP_ROOT . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('config.php not found — copy config.sample.php and fill in real values.');
        }
        $cfg = require $file;
    }
    return $cfg;
}

// --- session ---------------------------------------------------------------
(function (): void {
    $cfg = pp_config();
    session_name((string)($cfg['session_name'] ?? 'PP_SESS'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => (string)($cfg['cookie_path'] ?? '/'),
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (bool)($cfg['secure_cookie'] ?? false),
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
})();

// --- output helpers ------------------------------------------------------------

/** HTML-escape for echoing into markup. */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Send a JSON response and stop. Used by the API endpoints later. */
function pp_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// --- identity ----------------------------------------------------------------

/**
 * Stable pseudo-UUID derived from a normalised display name. The same name
 * always maps to the same id, so game / hand history stays coherent across
 * logins. Identity is NOT verified — the shared PIN is the only gate, and
 * anyone may sit down as any name. Good enough for a family card table.
 */
function pp_uuid_for_name(string $name): string
{
    $key = strtolower(trim((string)preg_replace('/\s+/', ' ', $name)));
    $hex = md5('pp:name:' . $key);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

/**
 * Synthetic uuid for a bot seated at $gameId/$seat. A distinct salt namespace
 * ('pp:bot:' vs pp_uuid_for_name()'s 'pp:name:') guarantees a bot's uuid can
 * never collide with a real player's name-derived one, no matter what name a
 * human picks.
 */
function pp_uuid_for_bot(int $gameId, int $seat): string
{
    $hex = md5('pp:bot:' . $gameId . ':' . $seat);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

/** Validate a chosen seat name. 2–24 chars: letters, numbers, space, - _ ' */
function pp_valid_name(string $name): bool
{
    return (bool)preg_match("/^[\\p{L}\\p{N} _'\\-]{2,24}$/u", $name);
}

/** Random RFC-4122 v4 UUID (for game_uuid, hand ids, etc.). */
function pp_uuid_v4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return sprintf('%s-%s-%s-%s-%s',
        substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4),
        substr($h, 16, 4), substr($h, 20, 12));
}

/** @return array{uuid:string,name:string,since:int}|null */
function pp_current_user(): ?array
{
    if (empty($_SESSION['pp_uuid']) || empty($_SESSION['pp_name'])) {
        return null;
    }
    return [
        'uuid'  => (string)$_SESSION['pp_uuid'],
        'name'  => (string)$_SESSION['pp_name'],
        'since' => (int)($_SESSION['pp_since'] ?? 0),
    ];
}

function pp_login(string $name): void
{
    session_regenerate_id(true);
    $_SESSION['pp_name']  = $name;
    $_SESSION['pp_uuid']  = pp_uuid_for_name($name);
    $_SESSION['pp_since'] = time();
}

function pp_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

// --- CSRF ------------------------------------------------------------------

function pp_csrf_token(): string
{
    if (empty($_SESSION['pp_csrf'])) {
        $_SESSION['pp_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['pp_csrf'];
}

function pp_csrf_check(?string $token): bool
{
    return !empty($_SESSION['pp_csrf'])
        && is_string($token)
        && hash_equals((string)$_SESSION['pp_csrf'], $token);
}
