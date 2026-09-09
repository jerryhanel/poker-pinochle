<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// pp_broadcast() — publish a realtime event to a channel.
//
// STUB for Phase 3. Phase 4 wires this to the self-hosted Soketi (Pusher
// protocol) using a hand-rolled HMAC trigger (pattern: w:\soketi-test\publish.php)
// and the new dedicated poker-pinochle app credentials from config.php ['soketi'].
//
// Endpoints already call it after every state change so Phase 4 is just filling
// in the body here.
// -----------------------------------------------------------------------------

function pp_broadcast(string $channel, string $event, array $payload): void
{
    $cfg = pp_config()['soketi'] ?? null;
    if (!$cfg || empty($cfg['host'])) {
        return; // not configured yet — no-op
    }

    // --- Phase 4 ---
    // $appId  = $cfg['app_id']; $key = $cfg['key']; $secret = $cfg['secret'];
    // $body   = json_encode(['name'=>$event,'channel'=>$channel,'data'=>json_encode($payload)]);
    // ksort'd auth_key/auth_timestamp/auth_version/body_md5, then
    // auth_signature = hash_hmac('sha256', "POST\n/apps/$appId/events\n$qs", $secret)
    // curl POST http://{$cfg['host']}:{$cfg['port']}/apps/$appId/events?...
}
