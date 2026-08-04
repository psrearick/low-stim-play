# Changelog

A terse, commit-by-commit record of what changed, newest first. No rationale or decisions here — see `docs/DEVLOG.md` for the *why* behind anything listed here, organized by feature instead of by commit. See `docs/TODO.md` for what's still outstanding.

## 2026-08-04

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
