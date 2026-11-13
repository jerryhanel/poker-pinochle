<?php
require __DIR__ . '/_boot.php';
use PokerPinochle\Lib\Api;

// POST (CSRF) — touch my presence, get back everyone currently online.
pp_api_need_post();
$body = pp_api_body();
pp_api_csrf($body);
$user = pp_api_user();

pp_api_run(static function () use ($user) {
    return Api::presencePing($user);
});
