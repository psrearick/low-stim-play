# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Each task below has a permanent ID number, assigned once and never reused or renumbered — even when an earlier task is completed and removed, the rest keep their existing numbers. Reference a task elsewhere as "TODO #N".

The Order of Work list is a separate, ordinary numbered list reflecting current work priority (position 1 = do next). Its position numbers are not task IDs and will drift out of sync with them as tasks are completed or reordered, so each entry's link text names the task's actual ID (e.g. "Task 3: ...").

## Order of Work

1. [Task 20: Apply CommonWordFilter to Word Blocks' answer selection](#20-apply-commonwordfilter-to-word-blocks-answer-selection)
2. [Task 28: Add a pre-game setup screen for Wordle's Hard Mode](#28-add-a-pre-game-setup-screen-for-wordles-hard-mode)
3. [Task 29: Support tapping a tile to edit a single letter mid-guess](#29-support-tapping-a-tile-to-edit-a-single-letter-mid-guess)
4. [Task 21: Change Wordle scoring to reflect guess count directly](#21-change-wordle-scoring-to-reflect-guess-count-directly)
5. [Task 25: Fix homepage game-grid responsive columns](#25-fix-homepage-game-grid-responsive-columns)
6. [Task 30: Fix the Game Menu's dangling divider, and consider making it a right-side sidebar](#30-fix-the-game-menus-dangling-divider-and-consider-making-it-a-right-side-sidebar)
7. [Task 32: Spec and build Fibbage](#32-spec-and-build-fibbage)
8. [Task 22: Notify players when they're challenged, and surface it in the UI](#22-notify-players-when-theyre-challenged-and-surface-it-in-the-ui)
9. [Task 23: Add "Sign in with Google"](#23-add-sign-in-with-google)
10. [Task 26: Add "average percent of board found" to Word Grid stats](#26-add-average-percent-of-board-found-to-word-grid-stats)
11. [Task 27: Split solo vs. multiplayer score stats on the Word Grid stats page](#27-split-solo-vs-multiplayer-score-stats-on-the-word-grid-stats-page)
12. [Task 10: Spec and build Pips](#10-spec-and-build-pips)
13. [Task 19: Spec and build Connections and Strands](#19-spec-and-build-connections-and-strands)

## Tasks

### 10. Spec and build Pips

Originally part of a single "spec and build NYT-style puzzle games" task covering Wordle, Pips, Spelling Bee, Strands, and Connections; split up (2026-08-23) once Wordle shipped and Connections/Strands turned out to need substantially different infrastructure (see Task 19) — Pips and Spelling Bee stayed together as this task since both were the two remaining games without an LLM-authored-content problem, even though (per their full specs) they ended up landing on different persistence models from each other.

**Word Hive (Spelling Bee) is now fully built** — see `docs/DEVLOG.md`'s own "Word Hive" section for the complete build writeup (resumable/daily wiring, the exclusion-filter reuse, the rank ladder, a real button-sizing bug caught by a geometry check). Remaining: **Pips**. Full spec: [`docs/specs/pips.md`](specs/pips.md) (ships as "Pips," no rename) — fully resolved and ready to build from, not just a skeleton. Unlike Spelling Bee/Wordle, Pips does **not** fit the zero-persistence `DailyPuzzle::seedFor()` model — resolving its "verify solution uniqueness?" open question in favor of building a real constraint solver made per-request generation too expensive to redo on every visit, so it needs its own new persisted `pips_puzzles` table instead (see that spec's section 4).

Once built, wire it into the shared puzzle-code/daily-puzzle/Challenge-a-Friend infrastructure Task 11 built for Boggle and Word Blocks (see `docs/DEVLOG.md`'s "Boggle" and "Word Blocks" sections), adapted for Pips' own persisted-puzzle model per its spec: add the game to `GameType::isChallengeable()`, generate its seed via `DailyPuzzle::seedFor()` (used to look up/generate-and-cache the matching `pips_puzzles` row), and add its own link column to the `/daily-puzzles` archive page. `docs/DEVLOG.md`'s "Word Hive" section is a complete worked example of the *zero-persistence* half of this task's shape end-to-end — Pips' own persisted-puzzle wiring needs its own care rather than assuming an identical copy-paste.

### 19. Spec and build Connections and Strands

Split out from Task 10 (2026-08-23) because both need real semantic content this app has no precedent for generating — its only existing LLM-backed service, `FamilyFeud\JudgeService`, judges a guess against a fixed answer list rather than authoring content — and likely need a persisted puzzle-content table rather than the on-the-fly seed derivation every other puzzle game (including Pips/Spelling Bee, Task 10) uses. That's a shared open design question worth resolving once rather than per-game (see either spec's section 1) before writing full build specs. Skeletons exist: [`docs/specs/connections.md`](specs/connections.md), [`docs/specs/strands.md`](specs/strands.md).

Strands additionally needs a new word-placement/grid-tiling solver with no existing precedent in this app (Boggle's `Solver` finds words in a fixed grid; Strands needs to construct a grid that fits a predetermined word list, the reverse problem).

Once built, wire each into the shared puzzle-code/daily-puzzle/Challenge-a-Friend infrastructure the same way as Task 10 (see that task's own wiring paragraph and `docs/DEVLOG.md`'s "Boggle"/"Word Blocks" sections).

### 20. Apply CommonWordFilter to Word Blocks' answer selection

Follow-up spun out of building Wordle, deliberately deferred rather than part of Wordle's own build. Apply `App\Services\Words\CommonWordFilter` (a general English word-frequency-plus-exclusion-list service, not scoped to Wordle — see `docs/DEVLOG.md`'s Wordle section and its 2026-08-23 sensitive-word-exclusion entry) to Word Blocks' own `Quartiles\PuzzleGenerator` answer-word selection (`docs/specs/quartiles.md`), since an obscure or off-putting Word Blocks answer word has the same problem Wordle's own answer selection was built to avoid — both the frequency floor (avoid obscure answers) and the `storage/app/excluded_words.txt` exclusion (avoid slurs/profanity/distressing words) apply equally to Word Blocks once wired in, since both live inside `CommonWordFilter::mostCommon()` itself.

### 21. Change Wordle scoring to reflect guess count directly

Moved from `docs/concerns.md` (2026-08-23): "What do points mean in Wordle? Shouldn't the score just be how many attempts it took you and the lower score is better."

Current formula (`resources/views/pages/wordle/⚡play.blade.php`'s `finishGame()`): `(MAX_GUESSES + 1 - count($guesses)) * 100` on a win (600/500/400/300/200/100 for 1–6 guesses), `0` on a loss — chosen only because `GameParticipant.score` needs an integer and higher-is-better matches every other game's stats tiles (see `docs/DEVLOG.md`'s Wordle section). The raw data this feedback wants already exists: `WordleStatsService::bestMetrics()` (`app/Services/Stats/WordleStatsService.php`) already computes and shows a separate "Fewest guesses to win" tile straight from `stats['guess_count']` — this is about what `GameParticipant.score` itself holds and how "Highest score" is framed, not about adding new tracking.

Would need: a new scoring convention (e.g. store guess count as the score, with a sentinel worse-than-any-win value for a loss such as `MAX_GUESSES + 1`), inverting "Highest score"'s sort direction and/or renaming that tile so a lower number reads as better, and checking `resources/views/components/player-stats.blade.php`'s generic "avg score" framing on the dashboard and `PlayerStatsService::forUser()`'s plain `avg('score')` call, since both currently assume every game's score is bigger-is-better.

### 22. Notify players when they're challenged, and surface it in the UI

Moved from `docs/concerns.md` (2026-08-23): "Challenging people doesn't seem to do anything. Should it email them and send them a notification in the app, and have a badge on the friends link in the menu, and a section in the friends page that shows your challenges."

Currently `GameChallengeService::send()` (`app/Services/Social/GameChallengeService.php`) only emails the recipient via `GameChallengeReceived` (`app/Notifications/GameChallengeReceived.php`, mail-only channel) — no in-app notification exists anywhere in the app (no `notifications` table, no database channel usage on `User` at all).

Existing precedent to build on: `resources/views/components/app-sidebar.blade.php` already has a working badge pattern next to the Friends link (`FriendService::pendingReceived($user)->count()`, rendered as a small pill, hidden when zero) — the same shape would work for "unread challenges," but there's currently nothing to count: challenges aren't persisted anywhere, only emailed, so a badge/list needs a new table (e.g. `game_challenges`: from/to user, game participant/link, read/actioned state) rather than deriving from existing data. The Friends page's current "activity feed" (`FriendService::activityFeed()`, `pages/⚡friends.blade.php`) is actually just friends' recent games with a generic "Challenge back" button — not a list of challenges received — so the new "section... that shows your challenges" is a genuinely new query/view, not a relabeling of the existing feed.

Also worth confirming before shipping: `docs/DEVLOG.md` already documents that queued challenge/friend-request emails silently failed to send in production for a while because no queue worker was provisioned — check that's still resolved before layering more notification volume onto the same queue.

### 23. Add "Sign in with Google"

Moved from `docs/concerns.md` (2026-08-23): "sign in with google."

Fully greenfield — no OAuth package installed (`laravel/socialite` is not in `composer.json`), no `google` entry in `config/services.php`, and no provider/`google_id` column on `users`. Fortify (`app/Providers/FortifyServiceProvider.php`) only handles email/password, 2FA, and passkeys — it has no social-login concept, so this doesn't hook into it.

Needs: `composer require laravel/socialite`; a `google` block in `config/services.php` (`client_id`, `client_secret`, `redirect`) plus an actual Google Cloud OAuth app/credentials; new redirect/callback routes and a controller (Socialite has no Fortify-style scaffolding for this); and a migration adding a nullable `google_id` (or a small `provider`/`provider_id` pair) to `users`, plus a decision for the collision case where a Google account's email already matches an existing password-based account (link vs. reject vs. auto-merge).

### 25. Fix homepage game-grid responsive columns

Moved from `docs/concerns.md` (2026-08-23): "Homepage layout, 3 columns, 2 columns, 1 column depending on screen size, all columns the same width."

`resources/views/welcome.blade.php`'s game list uses custom `.game-grid`/`.game-card` classes (not Tailwind utilities, unlike most other pages in this app) — the responsive column count/breakpoints live in `resources/css/app.css`'s `.game-grid` rule, not in the Blade template. Fix in `app.css` directly (adjust `grid-template-columns`/media queries so every column stays the same width at each breakpoint) rather than converting the markup to Tailwind grid classes, unless a broader Tailwind migration for this file is intended anyway.

### 26. Add "average percent of board found" to Word Grid stats

Moved from `docs/concerns.md` (2026-08-23): "Word grid stats, add average percent of the board."

`BoggleStatsService::statsFor()` (`app/Services/Stats/BoggleStatsService.php`) already computes a per-game `percentFound` (words found ÷ `total_valid_words`, itself already recorded per game from `Boggle\Solver::solve()`'s full board solution at play time) — only a **best** percent is currently surfaced, via `bestMetrics()`'s "Most of the board found" tile. There's no average across all games. Add an `averagePercentFound(User $user)`-style method (mirroring `bestMetrics()`'s existing filtering, which already skips participants with a null `total_valid_words` for older rows recorded before this metric existed) and a new tile on `resources/views/pages/profile/boggle/⚡index.blade.php`.

### 27. Split solo vs. multiplayer score stats on the Word Grid stats page

Moved from `docs/concerns.md` (2026-08-23): "The score in word grid should be distinct from the points you get in rooms, in that solo points [don't] deduct other peoples words. Combining the two scoring methods would cause incorrect stats. So, there should be multiplayer scores and solo scores as two separate stats. I think it is already set up this way on the backend, but it doesn't look like the frontend reflect this. Both stats should be shown on the frontend."

Confirmed: the backend already distinguishes the two. `GameSession.mode` (`GameMode::Solo`/`Online`/etc.) and `PlayerStatsService::avgScoreByMode()` (`app/Services/Stats/PlayerStatsService.php`) already compute separate solo/online averages precisely because Boggle's room score is rewritten into an "exclusive" score by `SharedSessionFinalizer` (a word found by more than one player scores nothing for anyone) — a genuinely different scale from solo scoring, not just a different mode label. This split already renders on the dashboard (`resources/views/components/player-stats.blade.php`, "X avg score (solo)" / "Y avg score (rooms)").

The gap is specifically the dedicated Word Grid stats page (`resources/views/pages/profile/boggle/⚡index.blade.php`): `BoggleStatsService::bestMetrics()` computes "Highest score" and friends across **all modes combined**, blending solo raw scores with room "exclusive" scores into one figure — exactly the mixed-scale problem `avgScoreByMode()`'s own docblock warns against, just not yet applied here. The "All games" table already shows a per-row Mode column, so this is about the aggregate tiles, not the raw list: split `bestMetrics()` into solo/online variants (or filter by mode before computing), mirroring `avgScoreByMode()`'s existing approach.

### 28. Add a pre-game setup screen for Wordle's Hard Mode

Moved from `docs/concerns.md` (2026-08-23): "Wordle/Five Letters needs a setup screen like the other games where Hard Mode can be selected. Hard Mode should not be a toggle on the actual game, but selected before beginning the game."

Hard Mode currently toggles in-game (`resources/views/pages/wordle/⚡play.blade.php`: `$hardMode`, `toggleHardMode()`) as a button sitting directly above the grid, disabled but still visible once the first guess is made — there's no separate setup step before play begins at all; Wordle starts playing immediately in `mount()`.

Yahtzee's component (`resources/views/pages/yahtzee/⚡play.blade.php`) is the existing pattern to match: a `$started` flag gates `@unless ($started)` setup markup (player count, names, an `$undoEnabled` toggle explicitly documented as "chosen on the setup screen") versus `@if ($started)` gameplay, flipped by a `startGame()` action. Bringing Wordle in line means adding an equivalent `$started` property, moving the Hard Mode toggle into a new pre-game setup block, gating the grid/keyboard behind `$started`, and adding a `startGame()` action — a bigger structural change than it sounds, since today nothing in Wordle defers rendering until an explicit start action.

### 29. Support tapping a tile to edit a single letter mid-guess

Moved from `docs/concerns.md` (2026-08-23): "Wordle/Five Letters - user should be able to tap the square of a letter in the current row and change just that letter before submitting, in addition to just typing the whole word."

Wordle's guess input today (`resources/views/pages/wordle/⚡play.blade.php`) is purely append/truncate: `pressKey()` always appends to the end of `$currentGuess`, `backspace()` always removes the last character, and the grid cells themselves have no click handler at all — there's no concept of a selected tile/cursor position anywhere in the component. Supporting "tap a tile in the current row, change just that letter" needs genuinely new state (e.g. `$selectedCol`), a click handler on each in-progress-row cell to set it, and rewriting `pressKey()`/`backspace()` to operate at `$selectedCol` (with auto-advance) instead of always the string's end — this isn't a small tweak to existing logic, it's a different editing model.

### 30. Fix the Game Menu's dangling divider, and consider making it a right-side sidebar

Moved from `docs/concerns.md` (2026-08-23): "The Game menu has a divider that doesn't seem to always be dividing things. When it isn't, it shouldn't be there. The game menu should just be a sidebar like the main menu, but on the right side."

Bug: `resources/views/layouts/app.blade.php`'s `#game-menu-dialog` renders a `.app-sidebar-divider` unconditionally whenever `$rules` is set, immediately after the Rules button, regardless of whether the `#game-actions` teleport target below it actually has any content at that moment (e.g. Wordle's own action buttons only render `@if ($ended)` — during active play the divider sits above nothing). Needs the divider conditioned on `#game-actions` actually having children — not directly checkable in Blade since that target is populated client-side via Alpine `@teleport`, so likely either a per-page PHP flag or a CSS/JS `:empty`-driven toggle.

Broader ask: replace the Game Menu's current modal (`<x-dialog id="game-menu-dialog">`, adopted specifically to stop it overlapping the score/timer HUD — see `docs/DEVLOG.md`'s navbar redesign entries) with an off-canvas sidebar like the main Menu drawer (`resources/views/components/app-sidebar.blade.php`, a real `<aside>` + backdrop, not a `<dialog>`), anchored to the right instead of the left. Reuses the same `#game-actions` teleport target and Rules button; adopting the main drawer's real `.app-sidebar-link`/`.app-sidebar-divider` structure (dividers there sit between always-rendered groups, never in front of a possibly-empty target) would resolve the divider bug as a side effect rather than needing a separate fix.

### 32. Spec and build Fibbage

Raised while building Punchline (2026-08-23): the user wants another submit-then-vote room game, Fibbage-style — players write a plausible-sounding fake answer to a trivia question, the real answer is seeded among the lies, and everyone votes on which one they think is true (two-sided scoring: points for fooling other players *and* for finding the real answer, not just "did you win a vote" the way Punchline/Best Match work).

Needs its own real design pass before a build spec, not just a copy of Punchline's shape — see `docs/specs/punchline.md` section 4 ("Abstraction scope") for why: Fibbage's defining trick (a real answer planted among player-submitted lies, plus the two-sided fooled-vs-found scoring) doesn't fit either Best Match's single-judge-pick or Punchline's proportional-vote-share-per-matchup shape, so the round/scoring model is a genuinely open question, not a known quantity. Also needs a trivia-question content source resolved (a static curated bank, matching Best Match's cards and Punchline's prompts, is the established low-risk default in this app — see `docs/specs/punchline.md` section 2's own reasoning for why LLM-authored content was deliberately avoided there) with real answers attached, which no existing seed data in this app provides.

Once Fibbage's actual shape is designed, revisit whether `App\Services\Punchline\BallotService`'s submit → anonymize-reveal → vote → tally cycle is still a good fit to extract into a shared class both games use — it was deliberately built decoupled from Punchline-specific concepts for exactly this reason (see `docs/DEVLOG.md`'s Punchline section), but the decision to actually extract it was deferred until there's a second real consumer to design the shared shape against, rather than guessed at with only one.

