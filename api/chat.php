<?php
require __DIR__ . '/_boot.php';

// POST { game?, text }  -> store + broadcast     (game omitted = lobby chat)
// GET  ?g=<uuid>&since=<id>  -> recent messages
$user   = pp_api_user();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($isPost) {
    $body = pp_api_body();
    pp_api_csrf($body);
    pp_api_run(static function () use ($user, $body) {
        return Api::chatPost($user, $body);
    });
}

pp_api_run(static function () use ($user) {
    return Api::chatList($user, [
        'game'  => $_GET['g'] ?? $_GET['game'] ?? '',
        'since' => $_GET['since'] ?? 0,
    ]);
});
