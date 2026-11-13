-- ============================================================================
-- Poker Pinochle — migration 0005_viewers
-- ============================================================================
-- Spectators. A viewer is not seated (no seat_no/side_no, so it can't reuse
-- game_player — that table's side_no is NOT NULL with an FK into game_side).
-- Viewers can watch the felt and post chat, but every game-action endpoint
-- requires a game_player row (see Api::seatOf), so a viewer can never bid,
-- declare, discard, play, ready up, or deal — the engine simply never sees
-- them as a seat.
--
-- Apply as a privileged user:
--     mysql -u root -p poker_pinochle < migrations/0005_viewers.sql
-- ============================================================================

USE poker_pinochle;

CREATE TABLE IF NOT EXISTS game_viewer (
  game_id       BIGINT UNSIGNED NOT NULL,
  user_uuid     VARCHAR(36) NOT NULL,
  display_name  VARCHAR(64) NOT NULL,
  joined_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (game_id, user_uuid),
  CONSTRAINT fk_gv_game FOREIGN KEY (game_id) REFERENCES game(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('0005_viewers')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
