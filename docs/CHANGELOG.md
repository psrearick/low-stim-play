# Changelog

A terse, commit-by-commit record of what changed, newest first. No rationale or decisions here — see `docs/DEVLOG.md` for the *why* behind anything listed here, organized by feature instead of by commit. See `docs/TODO.md` for what's still outstanding.

## 2026-08-06

- `68d528f` — Fixed six Farkle mobile/UX bugs found in real play (renamed "Farkle" to "10,000" for trademark, mobile scoreboard scroll, banked-dice single-row layout, portrait dice overlap, desktop score font size, and "Back to Room" doing nothing on game over in all four games). Made rooms a persistent, cross-game feature: new `RoomMember` table, a room hub page (`/room/{code}`), and a navbar "Rooms" dropdown for creating/joining rooms from anywhere; each game's setup screen now shows a "rooms you host" shortcut instead of its own Host/Join UI.
- `b4d321f` — Fixed the room hub's "Start a new game" not actually letting anyone but the host into the game (now broadcasts the same event `GameLobby::switchGame()` uses, so other members follow along); fixed the navbar Rooms dropdown positioning off-screen on mobile; fixed the dashboard's mobile menu having a transparent background (a CSS custom property was never actually defined).

## 2026-08-05

- `3b58943` — Fixed the same stale-`isMyTurn` bug in Family Feud's submitGuess/retryGuess/skipQuestion/swapControl (found while auditing after the Farkle fix); added a regression test verified against the pre-fix code.
- `efac313` — Fixed Farkle's scoring table (flat 4/5/6-of-a-kind values, plus straight/three-pairs/two-triplets), made Bank auto-include every scoring die, fixed several UI legibility/layout-jump issues, and fixed a real Livewire computed-property staleness bug in the Roll Again/Bank buttons.
- `ada75d4` — Added Farkle: a new dice game with standard scoring, hot dice, a configurable target score/final round, and both pass-and-play and online rooms at launch.
- `46eda6a` — Answered docs/specs/farkle.md's open design questions (scoring, win condition, modes, hot dice) per user decisions; spec is now ready to build from.
- `4738279` — Renamed Boggle, Yahtzee, and Family Feud to Word Grid, Yacht, and Survey Says everywhere a user can see the name, to avoid trademark issues; routes/enum values/class names/CSS classes left unchanged.
- `91fe114` — Added a daily-scheduled `users:prune-guests` command that deletes guest accounts older than 24 hours.
- `7af7613` — Allowed hosting online rooms while logged out (auto-provisions a guest account, same as joining already did); removed the real-account gate from all three setup screens' Host button.
- `340260b` — Decoupled online rooms from specific games (switch games mid-room via new "or play something else in this room" buttons, same code/seats); also fixed a real pre-existing bug where a genuine room replay 500'd on a UNIQUE constraint violation.
- `63f809a` — Added a persistent sidebar (Dashboard/All Games/Boggle/Yahtzee/Family Feud Stats/Edit Profile/Log Out) covering the Dashboard/Profile/Stats pages, replacing each page's own inconsistent Dashboard-button/back-link; off-canvas panel on mobile.
- `41ddea5` — Defaulted "Remember me" to checked on password login (matching passkey logins) and raised SESSION_LIFETIME to a year; users should now stay signed in for a long time instead of hitting a 2-hour idle cliff.
- `7db0d27` — Rewrote the Family Feud homepage card's description to explain the actual gameplay instead of the AI-judge implementation detail.
- `4f3bf33` — Fixed the remaining stats tables not scrolling on mobile (Yahtzee/Family Feud All Games tables, dashboard/public-profile recent-games list); added real-browser scroll-overflow tests.
- `6b5b5fa` — Added a Result stat tile to the individual Boggle game page (omitted, not a dash, for solo games); Yahtzee/Family Feud already had this.
- `f866d72` — Added a win/loss Result column to Boggle's All Games table (dash for solo games); also gave the now-wider table horizontal scroll on mobile instead of squashed columns.
- `51d54ca` — Added a paginated "All Games" stats page (`/profile/games`), listing every game across all types for the acting user, linked from the dashboard.
- `eb43262` — Fixed Boggle's dashboard win-rate calculation: restricted to online-room games, excluding any session where every participant scored zero; games played/avg score untouched. Added test coverage.

## 2026-08-04

- `6d23a2f` — Fixed a real duplicate-`GameParticipant`-row bug in shared Boggle sessions (mount() didn't restore already-finished state, so a reload allowed a replay); cleaned up the two confirmed-stale rows in the dev database; added regression tests.
- `f5ac113` — Added a "Delete Account" button (Danger Zone panel on `/profile`), gated behind `<x-confirm-dialog>`; hard-deletes the user (schema-native cascade/null-on-delete), logs out, redirects home. Added Feature + browser test coverage.
- `0941845` — Added a "Clear All Stats" button (Danger Zone panel on `/profile`), gated behind `<x-confirm-dialog>`, that deletes only the acting user's own `GameParticipant` rows; added Feature + browser test coverage.
- `4fc32cd` — Added reusable `<x-dialog>`/`<x-confirm-dialog>` Blade components (generic delegated open/close JS in `app.js`) and replaced Family Feud's three `wire:confirm()` uses (Skip Question/End Game/Reveal) with them; added `tests/Browser/FamilyFeudConfirmDialogsTest.php`.
- `ed3e751` — Rewrote `docs/specs/clear-the-deck.md` from a plain rules pastebin into a build spec matching the Farkle/Apples to Apples format (open design questions, data-model notes, accessibility), with the original rules kept verbatim in an appendix; added it to `docs/TODO.md` as #20.
- `8dcd88e` — Reorganized `docs/TODO.md` into a numbered index + one heading per task, ordered by dependency; added `docs/specs/farkle.md` and `docs/specs/apples-to-apples.md` as skeleton specs with open design questions rather than invented rules.
- `7bec359` — Added the Games/Smaller Items sections to `docs/TODO.md` (Apples to Apples, Farkle, online-rooms decoupling, anonymous room hosting, stats fixes/additions, navigation rework, Edit Profile clear-stats/delete-account, `wire:confirm` replacement, guest cleanup, 2FA).

## 2026-08-02

- `0493a13` — Added a host-only team-assignment screen between "Start Game" and the first round for Family Feud online rooms: `onRoomStarting()` no longer creates the session immediately, instead snapshotting ready players into an editable default (alternating) team/captain split; `moveToTeam()`/`makeCaptain()` let the host reshuffle before `startRound()` finalizes it, gated on both teams having at least one player.
- `0faa079` — Added online rooms for Family Feud (team/captain assignment, rotating turn pointer, captain-only steal gating, per-seat authorization on every mutating action, per-seat `GameParticipant` attribution) and new `/profile/family-feud` stats pages.
- `ea3f365` — Fixed a Yahtzee room bug where a seated player's own "Watch only" view still showed the live possible score for their open categories; added the current roll status and dice to the mobile Scores drawer.
- `b66bf85` — Replaced the two now-implemented Yahtzee room TODO items with the "Smaller Items" section heading (renamed from "Smaller, previously-flagged gaps").
- `6b41b74` — Regenerated `package-lock.json` to fix a Laravel Cloud build failure (`npm ci` missing a platform-specific `@emnapi/runtime@2.0.0-alpha.3` entry pulled in by Vite 8's Rolldown bundler).
- `c1e3c9e` — Added Yahtzee browser tests: full solo playthrough, mobile dice/Roll layout, and online-room hosting/joining/seated-play/spectator/mobile-drawer coverage; documented two `pest-plugin-browser` gotchas (`on()->mobile()` dropping clicks, concurrent `visit()` pages hanging) in `docs/DEVLOG.md` and `CLAUDE.md`/`AGENTS.md`.
- `f844278` — Installed Pest browser testing (Playwright) and the `--agent` verification probe; added a `Browser` testsuite and two real browser tests; documented both in `CLAUDE.md`/`AGENTS.md`.
- `b33f911` — Split the old phase-organized `docs/CHANGELOG.md` into `docs/DEVLOG.md` (feature-organized) and this terse commit log; documented both files plus `docs/TODO.md` in `CLAUDE.md`/`AGENTS.md`.
- `e3d8377` — Yahtzee: kept the mobile scoreboard drawer open across live state updates from other players (`wire:ignore.self`); widened scorecard columns on desktop and moved the Roll button onto the same row as the dice, shrinking the dice to fit.
