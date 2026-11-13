<?php
require __DIR__ . '/_boot.php';
use PokerPinochle\Lib\Api;

// action: create | join | watch | leave (POST, CSRF) | list (GET)
$body   = pp_api_body();
$action = (string) ($_GET['action'] ?? $body['action'] ?? '');

if ($action === 'list') {
    pp_api_run(static function () {
        return Api::lobbyList();
    });
}

pp_api_need_post();
pp_api_csrf($body);
$user = pp_api_user();

pp_api_run(static function () use ($action, $user, $body) {
    switch ($action) {
        case 'create': return Api::lobbyCreate($user, $body);
        case 'join':   return Api::lobbyJoin($user, $body);
        case 'watch':  return Api::lobbyWatch($user, $body);
        case 'leave':  return Api::lobbyLeave($user, $body);
        default:       throw new InvalidArgumentException("Unknown lobby action '{$action}'.");
    }
});
