<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// api/_boot.php — shared front matter for every endpoint. JSON in / JSON out,
// the PIN session, CSRF for same-origin POSTs, and exception -> HTTP mapping.
// -----------------------------------------------------------------------------

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../engine/autoload.php';
require_once __DIR__ . '/../lib/Api.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function pp_api_body(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return $_POST ?: [];
}

function pp_api_user(): array
{
    $u = pp_current_user();
    if ($u === null) {
        pp_api_fail(401, 'Sign in first.');
    }
    return $u;
}

function pp_api_need_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        pp_api_fail(405, 'POST only.');
    }
}

function pp_api_csrf(array $body): void
{
    $t = $_SERVER['HTTP_X_PP_CSRF'] ?? ($body['csrf'] ?? '');
    if (!is_string($t) || $t === '' || !hash_equals(pp_csrf_token(), $t)) {
        pp_api_fail(403, 'Bad or missing CSRF token.');
    }
}

function pp_api_ok(array $data = []): void
{
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pp_api_fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Run $fn and emit its array result; map engine exceptions to HTTP status. */
function pp_api_run(callable $fn): void
{
    try {
        pp_api_ok($fn());
    } catch (InvalidArgumentException $e) {
        pp_api_fail(400, $e->getMessage());
    } catch (DomainException $e) {
        pp_api_fail(409, $e->getMessage());
    } catch (Throwable $e) {
        error_log('[pp-api] ' . $e->getMessage());
        pp_api_fail(500, 'Server error.');
    }
}
