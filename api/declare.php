<?php
require __DIR__ . '/_boot.php';
pp_api_need_post();
$body = pp_api_body();
pp_api_csrf($body);
$user = pp_api_user();
pp_api_run(static function () use ($user, $body) {
    return Api::declare($user, $body);   // { game, trump: 'S'|'H'|'D'|'C'|'NT' }
});
