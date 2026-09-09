-- ============================================================================
-- Poker Pinochle — migration 0003_chat
-- ============================================================================
-- Table chat + lobby messages. game_id NULL = lobby-wide chat.
-- Kept for reconnect context / history; realtime delivery is Soketi (Phase 4).
--
-- Apply as a privileged user:
--     mysql -u root -p poker_pinochle < migrations/0003_chat.sql
-- ============================================================================

USE poker_pinochle;

CREATE TABLE IF NOT EXISTS chat_message (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  game_id       BIGINT UNSIGNED NULL,               -- NULL = lobby
  user_uuid     VARCHAR(36) NOT NULL,
  display_name  VARCHAR(64) NOT NULL,
  body          VARCHAR(500) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cm_game (game_id, id),
  CONSTRAINT fk_cm_game FOREIGN KEY (game_id) REFERENCES game(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('0003_chat')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
