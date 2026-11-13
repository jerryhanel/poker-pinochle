<?php
// ---------------------------------------------------------------------------
// Poker Pinochle — config template.
//
// Copy to `config.php` (gitignored) and fill in real values.
// The app will NOT start without config.php — there is no fallback.
// ---------------------------------------------------------------------------

return [
    'pin' => '0000',

    'session_name'  => 'PP_SESS',
    'cookie_path'   => '/poker-pinochle/',
    'secure_cookie' => true,

    'db' => [
        'host' => '127.0.0.1',
        'user' => 'pp_user',
        'pass' => '',
        'name' => 'poker_pinochle',
    ],

    'soketi' => [
        'scheme' => 'http',
        'host'   => '127.0.0.1',
        'port'   => 6001,
        'app_id' => '',
        'key'    => '',
        'secret' => '',
        'ws_url' => '',
    ],
];
