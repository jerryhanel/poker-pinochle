<?php
declare(strict_types=1);

namespace PokerPinochle\Lib;

// pp_db() is a plain function (not autoloadable), so load it explicitly.
require_once __DIR__ . '/db.php';

final class Container
{
    public Api $api;

    public function __construct()
    {
        $db          = pp_db();
        $gameStore   = new GameStore($db);
        $presence    = new Presence($db);
        $botPlayer   = new BotPlayer(new BotStrategy());
        $this->api   = new Api($gameStore, $presence, $botPlayer);
    }
}
