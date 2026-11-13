# Handoff — Namespace Migration (incomplete)

**Status: BROKEN. The app is returning "Server error." on lobby.php?action=list and likely other endpoints.**

## What happened

A previous Claude session was mid-way through a namespace migration — adding `namespace PokerPinochle\Engine` and `namespace PokerPinochle\Lib` to files that previously had no namespace. That session ran out of tokens and left the codebase in a half-edited state. The app was working before that session started.

## What is broken

`lobby.php?action=list` returns `{"ok":false,"error":"Server error."}`. All other API endpoints are likely broken too.

The root cause has NOT been confirmed yet — the investigation ran out of tokens before the fix was applied. The most likely candidate is a PHP error being swallowed by the `Throwable` catch in `pp_api_run()`.

## What was investigated (and ruled out)

- **Syntax errors** — none found anywhere (`php -l` clean on all files)
- **Missing DB tables/columns** — all tables exist (game_viewer, online_user, is_bot, team_vote_json, meet_url, etc.)
- **PHP 8-only syntax** — none found (str_contains, match, ?->, etc.)
- **Same-namespace resolution** — `Api.php` and `GameStore.php` are both in `PokerPinochle\Lib`, so bare `GameStore::` calls in `Api.php` resolve correctly without a `use` statement

## Most likely remaining suspects

1. **`lib/BotStrategy.php` or `lib/BotPlayer.php`** — these are new untracked files required by `Api.php` on every request load. If either has a fatal error (bad require path, PHP 7 incompatible syntax), every endpoint would fail. **This was not checked.**

2. **`engine/Trick.php`** — parameter renamed from `$trump` to `$inSuit` throughout. If any caller still passes the old name or if a call site was missed, it would throw. **Callers were not fully checked.**

3. **A `require_once` path broken by the namespace refactor** — some files moved from global to namespaced and their `require_once` paths may now be wrong relative to their load context.

## State of the working tree

- **~30 files modified** vs last commit (`c0c5803`, Sep 13 2026)
- **~15 new untracked files** (BotPlayer.php, BotStrategy.php, Presence.php, autoload.php, api/bot_act.php, etc.)
- **3 files deleted** (ARCHITECTURE.md, DESIGN.md, old HANDOFF.md)
- Nothing committed since Sep 13

## Recommended next steps

1. Enable PHP error display on the server temporarily, or check the PHP error log, to get the actual error message rather than "Server error."
2. That error message will point directly at the broken file/line.
3. Fix it, then re-disable error display.

The fastest path is: `tail -f /var/log/php_errors.log` (or wherever PHP errors go on that server) while hitting `lobby.php?action=list`.
