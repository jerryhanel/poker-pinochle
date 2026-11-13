-- ============================================================================
-- Poker Pinochle — migration 0007_in_suit
-- ============================================================================
-- Renames hand.trump -> hand.in_suit, and its 'NT' ("no trump") enum value to
-- 'NS' ("no suit") — the app no longer uses the word "trump" anywhere, inside
-- or outside the code. Widen-then-narrow the ENUM so existing 'NT' rows
-- convert cleanly instead of silently truncating to ''.
--
-- Apply as a privileged user:
--     mysql -u root -p poker_pinochle < migrations/0007_in_suit.sql
-- ============================================================================

USE poker_pinochle;

ALTER TABLE hand
  CHANGE COLUMN trump in_suit ENUM('S','H','D','C','NT','NS') NULL;

UPDATE hand SET in_suit = 'NS' WHERE in_suit = 'NT';

ALTER TABLE hand
  MODIFY COLUMN in_suit ENUM('S','H','D','C','NS') NULL;

INSERT INTO schema_migrations (version) VALUES ('0007_in_suit')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
