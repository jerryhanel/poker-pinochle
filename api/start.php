<?php
require __DIR__ . '/_boot.php';
use PokerPinochle\Lib\Api;
pp_api_need_post();
$body = pp_api_body();
pp_api_csrf($body);
$user = pp_api_user();
pp_api_run(static function () use ($user, $body) {
    return Api::start($user, $body);   // host-only; deals the first/next hand
});
