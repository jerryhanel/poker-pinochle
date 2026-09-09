<?php
// -----------------------------------------------------------------------------
// Poker Pinochle — local config
//
// Copy this file to `config.php` (which is gitignored) and set a real PIN.
// If `config.php` is missing, the app falls back to THIS file's values, so the
// sample PIN below must never be treated as secret.
// -----------------------------------------------------------------------------

return [
    // Shared "door code" for the whole game. Anyone with this PIN can sit down
    // under any name they choose. 4–8 digits.
    'pin' => '0000',

    // Session cookie name for this app (keeps it distinct from other apps on
    // the same host).
    'session_name' => 'PP_SESS',

    // Cookie path — the app is served from https://haneldev.zapto.org/poker-pinochle/
    'cookie_path' => '/poker-pinochle/',

    // Send the session cookie only over HTTPS. True in production.
    'secure_cookie' => true,

    // poker_pinochle database (app user pp_user @ '%': CRUD on poker_pinochle.*,
    // SELECT on sim_auth.*). MySQL lives on the NAS at 192.168.8.218 — NOT the
    // same host as the web server, so this is a LAN address, not localhost.
    'db' => [
        'host' => '192.168.8.218',
        'user' => 'pp_user',
        'pass' => 'CHANGE_ME',
        'name' => 'poker_pinochle',
    ],

    // Self-hosted Soketi (Pusher protocol).
    //   scheme/host/port : server -> Soketi HMAC trigger hop (LAN http is fine)
    //   app_id/key/secret: the Soketi app
    //   ws_url           : browser-facing wss:// endpoint for the client
    'soketi' => [
        'scheme' => 'http',
        'host'   => '192.168.8.218',
        'port'   => 6001,
        'app_id' => 'CHANGE_ME',
        'key'    => 'CHANGE_ME',
        'secret' => 'CHANGE_ME',
        'ws_url' => '',
    ],
];
