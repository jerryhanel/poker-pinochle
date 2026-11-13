-- ============================================================================
-- Poker Pinochle — migration 0009_meet_url
-- ============================================================================
-- Host-set meeting link (e.g. a Google Meet URL) for the table. Once set,
-- every seated player sees a "join the call" button on the felt that opens
-- it in a new window. NULL = none set.
--
-- Apply as a privileged user:
--     mysql -u root -p poker_pinochle < migrations/0009_meet_url.sql
-- ============================================================================

USE poker_pinochle;

ALTER TABLE game
  ADD COLUMN meet_url VARCHAR(500) NULL AFTER team_vote_json;

INSERT INTO schema_migrations (version) VALUES ('0009_meet_url')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
