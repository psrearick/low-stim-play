# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Each task below has a permanent ID number, assigned once and never reused or renumbered — even when an earlier task is completed and removed, the rest keep their existing numbers. Reference a task elsewhere as "TODO #N".

The Order of Work list is a separate, ordinary numbered list reflecting current work priority (position 1 = do next). Its position numbers are not task IDs and will drift out of sync with them as tasks are completed or reordered, so each entry's link text names the task's actual ID (e.g. "Task 3: ...").

## Order of Work

1. [Task 33: Convert the Game Menu to a right-side off-canvas sidebar](#33-convert-the-game-menu-to-a-right-side-off-canvas-sidebar)
2. [Task 10: Spec and build Pips](#10-spec-and-build-pips)
3. [Task 19: Spec and build Connections and Strands](#19-spec-and-build-connections-and-strands)
4. [Task 23: Add "Sign in with Google"](#23-add-sign-in-with-google)

## Tasks

### 10. Spec and build Pips

Originally part of a single "spec and build NYT-style puzzle games" task covering Wordle, Pips, Spelling Bee, Strands, and Connections; split up (2026-08-23) once Wordle shipped and Connections/Strands turned out to need substantially different infrastructure (see Task 19) — Pips and Spelling Bee stayed together as this task since both were the two remaining games without an LLM-authored-content problem, even though (per their full specs) they ended up landing on different persistence models from each other.

**Word Hive (Spelling Bee) is now fully built** — see `docs/DEVLOG.md`'s own "Word Hive" section for the complete build writeup (resumable/daily wiring, the exclusion-filter reuse, the rank ladder, a real button-sizing bug caught by a geometry check). Remaining: **Pips**. Full spec: [`docs/specs/pips.md`](specs/pips.md) (ships as "Pips," no rename) — fully resolved and ready to build from, not just a skeleton. Unlike Spelling Bee/Wordle, Pips does **not** fit the zero-persistence `DailyPuzzle::seedFor()` model — resolving its "verify solution uniqueness?" open question in favor of building a real constraint solver made per-request generation too expensive to redo on every visit, so it needs its own new persisted `pips_puzzles` table instead (see that spec's section 4).

Once built, wire it into the shared puzzle-code/daily-puzzle/Challenge-a-Friend infrastructure Task 11 built for Boggle and Word Blocks (see `docs/DEVLOG.md`'s "Boggle" and "Word Blocks" sections), adapted for Pips' own persisted-puzzle model per its spec: add the game to `GameType::isChallengeable()`, generate its seed via `DailyPuzzle::seedFor()` (used to look up/generate-and-cache the matching `pips_puzzles` row), and add its own link column to the `/daily-puzzles` archive page. `docs/DEVLOG.md`'s "Word Hive" section is a complete worked example of the *zero-persistence* half of this task's shape end-to-end — Pips' own persisted-puzzle wiring needs its own care rather than assuming an identical copy-paste.

### 19. Spec and build Connections and Strands

Split out from Task 10 (2026-08-23) because both need real semantic content this app has no precedent for generating — its only existing LLM-backed service, `FamilyFeud\JudgeService`, judges a guess against a fixed answer list rather than authoring content — and likely need a persisted puzzle-content table rather than the on-the-fly seed derivation every other puzzle game (including Pips/Spelling Bee, Task 10) uses. That's a shared open design question worth resolving once rather than per-game (see either spec's section 1) before writing full build specs. Skeletons exist: [`docs/specs/connections.md`](specs/connections.md), [`docs/specs/strands.md`](specs/strands.md).

Strands additionally needs a new word-placement/grid-tiling solver with no existing precedent in this app (Boggle's `Solver` finds words in a fixed grid; Strands needs to construct a grid that fits a predetermined word list, the reverse problem).

Once built, wire each into the shared puzzle-code/daily-puzzle/Challenge-a-Friend infrastructure the same way as Task 10 (see that task's own wiring paragraph and `docs/DEVLOG.md`'s "Boggle"/"Word Blocks" sections).

### 23. Add "Sign in with Google"

Moved from `docs/concerns.md` (2026-08-23): "sign in with google."

Fully greenfield — no OAuth package installed (`laravel/socialite` is not in `composer.json`), no `google` entry in `config/services.php`, and no provider/`google_id` column on `users`. Fortify (`app/Providers/FortifyServiceProvider.php`) only handles email/password, 2FA, and passkeys — it has no social-login concept, so this doesn't hook into it.

Needs: `composer require laravel/socialite`; a `google` block in `config/services.php` (`client_id`, `client_secret`, `redirect`) plus an actual Google Cloud OAuth app/credentials; new redirect/callback routes and a controller (Socialite has no Fortify-style scaffolding for this); and a migration adding a nullable `google_id` (or a small `provider`/`provider_id` pair) to `users`, plus a decision for the collision case where a Google account's email already matches an existing password-based account (link vs. reject vs. auto-merge).

### 33. Convert the Game Menu to a right-side off-canvas sidebar

Split out from the former Task 30 (2026-08-26) once that task's actual bug (the dangling divider) was fixed directly in CSS — this is the remaining, separate part of the original report: "the game menu should just be a sidebar like the main menu, but on the right side." A real UI redesign, not a bug fix, so deliberately not bundled into the same pass.

Replace the Game Menu's current modal (`<x-dialog id="game-menu-dialog">`, adopted specifically to stop it overlapping the score/timer HUD — see `docs/DEVLOG.md`'s navbar redesign entries) with an off-canvas sidebar like the main Menu drawer (`resources/views/components/app-sidebar.blade.php`, a real `<aside>` + backdrop, not a `<dialog>`), anchored to the right instead of the left. Reuses the same `#game-actions` teleport target and Rules button; adopting the main drawer's real `.app-sidebar-link`/`.app-sidebar-divider` structure (dividers there sit between always-rendered groups, never in front of a possibly-empty target) would resolve the divider-visibility concern as a side effect of the redesign too, on top of the CSS fix already in place.

