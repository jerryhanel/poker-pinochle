-- ============================================================================
-- Poker Pinochle — migration 0004_ready
-- ============================================================================
-- Per-hand "ready for the next hand" list. After a hand is scored every seated
-- player clicks Ready; the next deal is blocked until all seats are in the list.
-- JSON array of seat numbers, e.g. [0,1,2,3]. NULL / [] = nobody ready yet.
--
-- Apply as a privileged user:
--     mysql -u root -p poker_pinochle < migrations/0004_ready.sql
-- ============================================================================

USE poker_pinochle;

ALTER TABLE hand
  ADD COLUMN ready_json JSON NULL AFTER state_json;

INSERT INTO schema_migrations (version) VALUES ('0004_ready')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
