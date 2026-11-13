-- ============================================================================
-- Poker Pinochle — migration 0008_team_vote
-- ============================================================================
-- Pre-deal team-pairing vote for 'teams' mode. Once a teams-mode lobby fills
-- every seat, each player proposes their own teammate(s); if enough players
-- agree on the same split it's used, otherwise it's randomized. See
-- lib/GameStore.php::teamVoteSubmit() for the resolution algorithm.
--
-- Shape: { "<user_uuid>": { "picks": ["<uuid>", ...], "locked": true|false } }
-- NULL = no vote in progress (cutthroat games, or a teams lobby not yet full,
-- or a teams lobby whose vote already resolved).
--
-- Apply as a privileged user:
--     mysql -u root -p poker_pinochle < migrations/0008_team_vote.sql
-- ============================================================================

USE poker_pinochle;

ALTER TABLE game
  ADD COLUMN team_vote_json JSON NULL AFTER rules_json;

INSERT INTO schema_migrations (version) VALUES ('0008_team_vote')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
