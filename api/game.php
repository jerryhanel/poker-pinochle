<?php
require __DIR__ . '/_boot.php';
use PokerPinochle\Lib\Api;

// GET ?g=<uuid> — the full state slice the caller may see (own hand only).
$user = pp_api_user();
pp_api_run(static function () use ($user) {
    return Api::game($user, ['game' => $_GET['g'] ?? $_GET['game'] ?? '']);
});
