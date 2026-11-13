-- ============================================================================
-- Poker Pinochle — migration 0006_presence
-- ============================================================================
-- Who's online, for the "Users" menu. Not tied to any game — a client pings
-- this on a timer (see lib/Presence.php) whenever the app is open at all.
-- A row older than the online window (Presence::ONLINE_WINDOW_SECONDS) is
-- pruned the next time anyone pings, so this table stays small on its own.
--
-- Apply as a privileged user:
--     mysql -u root -p poker_pinochle < migrations/0006_presence.sql
-- ============================================================================

USE poker_pinochle;

CREATE TABLE IF NOT EXISTS online_user (
  user_uuid     VARCHAR(36) NOT NULL,
  display_name  VARCHAR(64) NOT NULL,
  last_seen_at  DATETIME NOT NULL,
  PRIMARY KEY (user_uuid),
  KEY idx_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('0006_presence')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
