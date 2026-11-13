-- ============================================================================
-- Poker Pinochle — migration 0010_bot_seats
-- ============================================================================
-- Bot seats. A bot is just a game_player row (synthetic uuid) with is_bot=1 —
-- seated the same way a human is, via GameStore::seatPlayer(). Seating a bot
-- requires a unanimous vote from every other currently-connected seated human;
-- bot_votes_json holds the in-progress per-seat proposals, keyed by target
-- seat number, e.g. {"3": {"proposedBy": "<uuid>", "ballots": {"<uuid>": true}}}.
--
-- Apply as a privileged user:
--     mysql -u root -p poker_pinochle < migrations/0010_bot_seats.sql
-- ============================================================================

USE poker_pinochle;

ALTER TABLE game_player
  ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0 AFTER is_host;

ALTER TABLE game
  ADD COLUMN bot_votes_json MEDIUMTEXT NULL AFTER team_vote_json;

INSERT INTO schema_migrations (version) VALUES ('0010_bot_seats')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
