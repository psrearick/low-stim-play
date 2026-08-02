# Changelog

A terse, commit-by-commit record of what changed, newest first. No rationale or decisions here — see `docs/DEVLOG.md` for the *why* behind anything listed here, organized by feature instead of by commit. See `docs/TODO.md` for what's still outstanding.

## 2026-08-02

- `f844278` — Installed Pest browser testing (Playwright) and the `--agent` verification probe; added a `Browser` testsuite and two real browser tests; documented both in `CLAUDE.md`/`AGENTS.md`.
- `b33f911` — Split the old phase-organized `docs/CHANGELOG.md` into `docs/DEVLOG.md` (feature-organized) and this terse commit log; documented both files plus `docs/TODO.md` in `CLAUDE.md`/`AGENTS.md`.
- `e3d8377` — Yahtzee: kept the mobile scoreboard drawer open across live state updates from other players (`wire:ignore.self`); widened scorecard columns on desktop and moved the Roll button onto the same row as the dice, shrinking the dice to fit.
