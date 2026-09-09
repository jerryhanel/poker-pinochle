-- ============================================================================
-- Poker Pinochle — migration 0001_init
-- ============================================================================
-- Creates a dedicated database `poker_pinochle` and the full schema.
--
-- Players are referenced by `user_uuid` (VARCHAR(36)) from sgc-auth's
-- sim_auth.sim_auth_user.user_uuid. NO cross-database foreign key is declared
-- on user_uuid on purpose (keeps the two databases independently movable);
-- the column is indexed instead. All in-schema relationships DO use FKs with
-- ON DELETE CASCADE, so deleting a `game` row cleans up everything under it.
--
-- Engine/charset match sgc-auth: InnoDB + utf8mb4.
--
-- If you would rather this live inside an existing database instead of its own,
-- delete the CREATE DATABASE / USE lines below and prefix every table name
-- with `pp_`.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS poker_pinochle
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE poker_pinochle;

-- Optional dedicated app user (edit the password, then uncomment):
-- CREATE USER IF NOT EXISTS 'pp_user'@'localhost' IDENTIFIED BY 'CHANGE_ME';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON poker_pinochle.* TO 'pp_user'@'localhost';
-- FLUSH PRIVILEGES;

-- ----------------------------------------------------------------------------
-- migration bookkeeping
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
  version     VARCHAR(32) NOT NULL PRIMARY KEY,
  applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- game — one match instance (lobby through completion)
-- ----------------------------------------------------------------------------
-- rules_json captures the RESOLVED rule toggles for this game so a match is
-- reproducible even if defaults change later. Expected shape:
--   {
--     "deck": 48, "hand_size": 5,
--     "bid_min": <player_count>, "bid_max": 20,
--     "auction": "single_pass", "all_pass": "dealer_stuck_at_min",
--     "keep_min": 2,                 -- 0 for 4-5p, 2 for 6p, 3 for 7-8p
--     "stand_pat": true,
--     "discard_counters_ok": true,
--     "draw_order": "bid_winner_first",
--     "reshuffle_includes_round_discards": true,
--     "meld_counts_toward_bid": true,
--     "meld_requires_trick": null,   -- UNRESOLVED (DESIGN.md §10.8)
--     "meld_card_reuse": null,       -- UNRESOLVED (DESIGN.md §10.7)
--     "meld_has_run": false,         -- UNRESOLVED (DESIGN.md §10.6)
--     "make_is_gte": true,
--     "must_bid_it_out": false,
--     "set_penalty": "minus_bid_and_forfeit_meld"
--   }
CREATE TABLE IF NOT EXISTS game (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  game_uuid         CHAR(36) NOT NULL,
  status            ENUM('lobby','active','complete','abandoned') NOT NULL DEFAULT 'lobby',
  mode              ENUM('teams','cutthroat') NOT NULL,
  player_count      TINYINT UNSIGNED NOT NULL,
  target_score      SMALLINT UNSIGNED NOT NULL,       -- 4p=50, 6p=150, 8p=200; cutthroat TBD
  host_user_uuid    VARCHAR(36) NOT NULL,
  is_private        TINYINT(1) NOT NULL DEFAULT 0,
  join_code         VARCHAR(12) NULL,                 -- share code for private tables
  rules_json        JSON NOT NULL,
  current_hand_no   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  winning_side_no   TINYINT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at        DATETIME NULL,
  ended_at          DATETIME NULL,
  UNIQUE KEY uq_game_uuid (game_uuid),
  UNIQUE KEY uq_join_code (join_code),
  KEY idx_status (status),
  KEY idx_host (host_user_uuid),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- game_side — a scoring side within a game
--   teams mode:     side_no 0 and 1
--   cutthroat mode: side_no 0..player_count-1 (one player each)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS game_side (
  game_id    BIGINT UNSIGNED NOT NULL,
  side_no    TINYINT UNSIGNED NOT NULL,
  label      VARCHAR(32) NULL,
  score      INT NOT NULL DEFAULT 0,          -- running total, updated per hand
  is_winner  TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (game_id, side_no),
  CONSTRAINT fk_side_game FOREIGN KEY (game_id) REFERENCES game(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- game_player — a seat at the table
--   seat_no is 0-based, clockwise. Seating strictly alternates between the two
--   sides in teams mode (A-B-A-B...), enforced by the app at seat assignment.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS game_player (
  game_id       BIGINT UNSIGNED NOT NULL,
  seat_no       TINYINT UNSIGNED NOT NULL,
  side_no       TINYINT UNSIGNED NOT NULL,
  user_uuid     VARCHAR(36) NOT NULL,
  display_name  VARCHAR(64) NOT NULL,          -- snapshot of user_name at join
  is_host       TINYINT(1) NOT NULL DEFAULT 0,
  connection    ENUM('connected','disconnected','left') NOT NULL DEFAULT 'connected',
  joined_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at       DATETIME NULL,
  PRIMARY KEY (game_id, seat_no),
  UNIQUE KEY uq_game_user (game_id, user_uuid),
  KEY idx_user (user_uuid),
  CONSTRAINT fk_gp_game FOREIGN KEY (game_id) REFERENCES game(id) ON DELETE CASCADE,
  CONSTRAINT fk_gp_side FOREIGN KEY (game_id, side_no) REFERENCES game_side(game_id, side_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- hand — one deal within a game
--   deck_json: the full 48-card shuffled order this hand was dealt from
--              (audit + replay). Card codes: rank in {9,T,J,Q,K,A}, suit in
--              {S,H,D,C}, e.g. "AS","TS","KH","QD","JC","9S".
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hand (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  game_id             BIGINT UNSIGNED NOT NULL,
  hand_no             SMALLINT UNSIGNED NOT NULL,
  dealer_seat         TINYINT UNSIGNED NOT NULL,
  phase               ENUM('auction','declare','draw','meld','play','scored') NOT NULL DEFAULT 'auction',
  trump               ENUM('S','H','D','C','NT') NULL,       -- set at declare
  bid_winner_seat     TINYINT UNSIGNED NULL,
  bid_winner_side_no  TINYINT UNSIGNED NULL,
  winning_bid         SMALLINT UNSIGNED NULL,
  bid_made            TINYINT(1) NULL,                        -- only meaningful for the bidding side
  deck_json           JSON NOT NULL,
  started_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  scored_at           DATETIME NULL,
  UNIQUE KEY uq_hand (game_id, hand_no),
  KEY idx_hand_game (game_id),
  CONSTRAINT fk_hand_game FOREIGN KEY (game_id) REFERENCES game(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- hand_side — per-side outcome of a hand
--   hand_score is what actually hit the running total: for the bidding side
--   that is (meld + counters) when made, or -(winning_bid) when set (and meld
--   is forfeited). Non-bidding sides score 0.
--   score_after is game_side.score immediately after this hand (running history).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hand_side (
  hand_id             BIGINT UNSIGNED NOT NULL,
  side_no             TINYINT UNSIGNED NOT NULL,
  is_bidding_side     TINYINT(1) NOT NULL DEFAULT 0,
  meld_points         SMALLINT NOT NULL DEFAULT 0,
  counters_captured   SMALLINT NOT NULL DEFAULT 0,
  hand_score          SMALLINT NOT NULL DEFAULT 0,
  score_after         INT NOT NULL DEFAULT 0,
  PRIMARY KEY (hand_id, side_no),
  CONSTRAINT fk_hs_hand FOREIGN KEY (hand_id) REFERENCES hand(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- hand_player — per-player detail of a hand (deal, draw, meld). Replay backbone.
--   meld_json: [{ "type":"marriage_trump", "cards":["KS","QS"], "points":4 }, ...]
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hand_player (
  hand_id        BIGINT UNSIGNED NOT NULL,
  seat_no        TINYINT UNSIGNED NOT NULL,
  user_uuid      VARCHAR(36) NOT NULL,
  dealt_json     JSON NOT NULL,                -- 5 cards dealt
  discards_json  JSON NOT NULL,                -- cards pitched ([] if stand pat)
  drawn_json     JSON NOT NULL,                -- cards received ([] if stand pat)
  final_json     JSON NOT NULL,               -- 5-card hand after the draw
  draw_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  meld_json      JSON NULL,
  meld_points    SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (hand_id, seat_no),
  KEY idx_hp_user (user_uuid),
  CONSTRAINT fk_hp_hand FOREIGN KEY (hand_id) REFERENCES hand(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- bid — the one-trip auction, one row per turn, in order
--   action 'stuck' = dealer forced to the minimum after everyone passed.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bid (
  hand_id     BIGINT UNSIGNED NOT NULL,
  seq         TINYINT UNSIGNED NOT NULL,       -- 1..player_count
  seat_no     TINYINT UNSIGNED NOT NULL,
  user_uuid   VARCHAR(36) NOT NULL,
  action      ENUM('bid','pass','stuck') NOT NULL,
  amount      SMALLINT UNSIGNED NULL,          -- NULL for pass
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (hand_id, seq),
  KEY idx_bid_user (user_uuid),
  CONSTRAINT fk_bid_hand FOREIGN KEY (hand_id) REFERENCES hand(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- trick — 5 per hand
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trick (
  hand_id      BIGINT UNSIGNED NOT NULL,
  trick_no     TINYINT UNSIGNED NOT NULL,      -- 1..5
  leader_seat  TINYINT UNSIGNED NOT NULL,
  winner_seat  TINYINT UNSIGNED NULL,
  counters     SMALLINT NOT NULL DEFAULT 0,    -- counter points contained in this trick
  PRIMARY KEY (hand_id, trick_no),
  CONSTRAINT fk_trick_hand FOREIGN KEY (hand_id) REFERENCES hand(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- trick_play — one row per card played
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trick_play (
  hand_id          BIGINT UNSIGNED NOT NULL,
  trick_no         TINYINT UNSIGNED NOT NULL,
  play_no          TINYINT UNSIGNED NOT NULL,  -- 1..player_count, order played
  seat_no          TINYINT UNSIGNED NOT NULL,
  card             CHAR(2) NOT NULL,           -- e.g. "AS","TS","KH","QD","JC","9S"
  is_winning_card  TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (hand_id, trick_no, play_no),
  CONSTRAINT fk_tp_hand FOREIGN KEY (hand_id) REFERENCES hand(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- v_player_stats — convenience rollup for profile / leaderboard pages.
-- Not performance-tuned; fine for card-game volumes. Swap for a maintained
-- table later if needed.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_player_stats AS
SELECT
  gp.user_uuid,
  COUNT(DISTINCT gp.game_id)                                                    AS games_joined,
  COUNT(DISTINCT CASE WHEN gs.is_winner = 1 THEN gp.game_id END)                AS games_won,
  COUNT(DISTINCT hp.hand_id)                                                    AS hands_played,
  COUNT(DISTINCT CASE WHEN h.bid_winner_seat = hp.seat_no THEN h.id END)        AS hands_bid_won,
  COUNT(DISTINCT CASE WHEN h.bid_winner_seat = hp.seat_no
                       AND h.bid_made = 1 THEN h.id END)                        AS hands_bid_made,
  COUNT(DISTINCT CASE WHEN h.bid_winner_seat = hp.seat_no
                       AND h.bid_made = 0 THEN h.id END)                        AS hands_bid_set
FROM game_player gp
JOIN game_side  gs ON gs.game_id = gp.game_id AND gs.side_no = gp.side_no
LEFT JOIN hand        h  ON h.game_id = gp.game_id
LEFT JOIN hand_player hp ON hp.hand_id = h.id AND hp.user_uuid = gp.user_uuid
GROUP BY gp.user_uuid;

-- ----------------------------------------------------------------------------
INSERT INTO schema_migrations (version) VALUES ('0001_init')
  ON DUPLICATE KEY UPDATE applied_at = applied_at;
