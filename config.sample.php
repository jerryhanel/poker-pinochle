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
];
