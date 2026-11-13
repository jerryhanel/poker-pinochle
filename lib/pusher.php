<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// -----------------------------------------------------------------------------
// pp_broadcast() — publish a realtime event to a Soketi channel.
//
// Hand-rolled Pusher HTTP trigger (no Composer): ksort'd auth params, body_md5,
// HMAC-SHA256 signature over "POST\n<path>\n<querystring>", curl POST.
//
// Server -> Soketi hop. The browser -> Soketi hop needs a wss:// URL set in
// config ['soketi']['ws_url'].
//
// @return array{ok:bool, skipped:bool, code:int, error:string}
// -----------------------------------------------------------------------------

function pp_broadcast(string $channel, string $event, array $payload): array
{
    $cfg = pp_config()['soketi'] ?? null;
    if (!$cfg || empty($cfg['host']) || empty($cfg['app_id'])) {
        return ['ok' => false, 'skipped' => true, 'code' => 0, 'error' => 'soketi not configured'];
    }

    $body = json_encode([
        'name'     => $event,
        'channels' => [$channel],
        'data'     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ], JSON_UNESCAPED_SLASHES);

    $path   = '/apps/' . $cfg['app_id'] . '/events';
    $params = [
        'auth_key'       => $cfg['key'],
        'auth_timestamp' => (string) time(),
        'auth_version'   => '1.0',
        'body_md5'       => md5($body),
    ];
    ksort($params);
    $qs  = http_build_query($params);
    $sig = hash_hmac('sha256', "POST\n{$path}\n{$qs}", (string) $cfg['secret']);

    $scheme = $cfg['scheme'] ?? 'http';
    $url    = "{$scheme}://{$cfg['host']}:{$cfg['port']}{$path}?{$qs}&auth_signature={$sig}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 4,
    ]);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $ok = $err === '' && $code === 200;
    if (!$ok) {
        error_log("[pp-broadcast] {$channel}/{$event}: " . ($err !== '' ? $err : "HTTP {$code} {$res}"));
    }
    return [
        'ok'      => $ok,
        'skipped' => false,
        'code'    => $code,
        'error'   => $err !== '' ? $err : ($ok ? '' : (string) $res),
    ];
}
