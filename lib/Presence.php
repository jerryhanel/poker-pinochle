<?php
declare(strict_types=1);

namespace PokerPinochle\Lib;

use mysqli;

require_once __DIR__ . '/db.php';

final class Presence
{
    /** A user with no ping in this many seconds is considered offline and pruned. */
    const ONLINE_WINDOW_SECONDS = 60;

    /**
     * Record that $uuid is online right now, sweep anyone gone stale, and
     * return everyone currently online (name-sorted), self included.
     *
     * @return array<int,array{uuid:string,name:string}>
     */
    public static function touch(mysqli $db, string $uuid, string $name): array
    {
        $stmt = $db->prepare(
            "INSERT INTO online_user (user_uuid, display_name, last_seen_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), last_seen_at = NOW()"
        );
        $stmt->bind_param('ss', $uuid, $name);
        $stmt->execute();

        $window = self::ONLINE_WINDOW_SECONDS;
        $db->query("DELETE FROM online_user WHERE last_seen_at < (NOW() - INTERVAL {$window} SECOND)");

        $rows = $db->query(
            "SELECT user_uuid, display_name FROM online_user ORDER BY display_name"
        )->fetch_all(MYSQLI_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['uuid' => $r['user_uuid'], 'name' => $r['display_name']];
        }
        return $out;
    }
}
