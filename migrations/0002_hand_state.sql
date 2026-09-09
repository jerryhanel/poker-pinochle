-- ============================================================================
-- Poker Pinochle — migration 0002_hand_state
-- ============================================================================
-- Adds a working-state blob to `hand`. The engine's full in-flight hand state
-- (State->hand: hands, stock, auction, current trick, tallies, ...) is stored
-- here as JSON and is AUTHORITATIVE while a hand is in progress. The normalized
-- bid / trick / trick_play / hand_player / hand_side rows are written when the
-- hand is scored and are the durable history (and what v_player_stats reads).
--
-- pp_user cannot run DDL. Apply as a privileged user:
--     mysql -u root -p poker_pinochle < migrations/0002_hand_state.sql
-- ============================================================================

USE poker_pinochle;

ALTER TABLE hand
  ADD COLUMN state_json JSON NULL AFTER deck_json;

INSERT INTO schema_migrations (version) VALUES ('0002_hand_state')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
