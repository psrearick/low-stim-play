# Changelog

A terse, commit-by-commit record of what changed, newest first. No rationale or decisions here — see `docs/DEVLOG.md` for the *why* behind anything listed here, organized by feature instead of by commit. See `docs/TODO.md` for what's still outstanding.

## 2026-08-28

- Fixed `room-new-game-notice` never showing anything until a new game's `GameSession` actually existed, forcing everyone to leave an old end-game page before a join link appeared -- it now also surfaces during the host's setup/gathering-players window, matching the room hub's own pending-game banner.
- Added a dismiss button to `room-new-game-notice` (it previously had none), which re-hides only the currently-shown target, so a later, genuinely different notice still surfaces.
- Restructured Punchline's round UI into 3 filtered player-centric steps: only the matchups you still need to write, then only the ones you can still vote on, then (at round reveal) every matchup's full results -- replacing the old board that showed every matchup's status at once regardless of whether you could act on it.
- Fixed Punchline's vote-button and Hoax's vote-button text overflowing past the button's edges on long player-submitted answers.
- Extended Hoax's uppercase display fix from just the answer to the entire prompt sentence.

## 2026-08-27

- Extended Punchline's writing and voting timers to a flat 3 minutes each, every round (previously 120s/240s writing and 30s/90s voting, split by round).
- Fixed Punchline's answer input zooming the viewport in on focus on mobile, by floor-ing its font-size at 16px.
- Changed Punchline's odd-player-count pairing (rounds 1-2) from two shared 3-player matchups to a Hamiltonian cycle of plain 2-player matchups, so every prompt is answered by exactly 2 players and every player votes only on the one prompt they weren't in, for any player count.
- Fixed Punchline's final round requiring 3 answers per player instead of 1 (still 3 stackable votes) -- vote allowance is now its own field on each matchup, decoupled from answer count.
- Added Hoax, a new Fibbage-style room game (TODO Task 32): each round, everyone writes a fake fill-in-the-blank answer to a trivia question, the real answer is seeded anonymously among the lies, and everyone votes on which one they think is true -- fooling other players and finding the truth both earn points. Fixed 5-round game, 3-8 players. New `hoax_questions` bank (84 questions), `Hoax\{ScoringService,FillerAnswers}`, profile stats, and homepage/sidebar wiring.
- Moved `BallotService` from `App\Services\Punchline` to a shared `App\Services\SubmitVote` namespace now that Hoax reuses it, and added a `voterChoices()` method for Hoax's own reveal needs.
- Fixed a pre-existing bug where `pages::profile.games`'s route-lookup match was missing a `GameType::Punchline` arm entirely.
- Fixed game-card button text overflowing its own button on the homepage at every screen size, by letting long labels (e.g. "Clear the Deck (Tens)") wrap onto a second line instead of forcing a single un-wrappable line past the button's edges.
- Shortened every homepage game-card button's label to just its action ("Play", "Group Play", "Dice Only") instead of repeating the game name already shown as the card's own heading; added an `aria-label` to each button restoring that context for screen readers.
- Fully audited and replaced Hoax's question bank (84 -> 83 entries): removed viral/common-knowledge and moderately-known facts, reworded questions whose blank gave the answer away, and fact-checked every specific claim against real sources.
- Fixed Hoax silently allowing a player to submit the literal correct answer (or a known alternate spelling) as their own fake answer -- now rejected at submission time and the player is asked to write something else, without losing their turn. New `alternate_answers` field per question.
- Fixed Hoax's voting/reveal display always showing the real answer's clean, consistent capitalization next to players' inconsistently-typed fake answers, a tell across a whole game -- every answer now renders in uppercase.
- Fixed `HoaxQuestionSeeder` never removing a question dropped from the bank on re-seed, leaving stale rows behind indefinitely.

## 2026-08-26

- Prevented edge-swipe drags from triggering iOS Safari's back-navigation gesture, most noticeable on Word Hive's edge-to-edge honeycomb.
- Renamed "Word Blocks" to "Quads" (TODO Task 24).
- Applied `CommonWordFilter` to Quads' answer-word selection (TODO Task 20).
- Moved Wordle's Hard Mode toggle to a pre-game setup screen instead of an in-game toggle (TODO Task 28).
- Added the ability to tap a tile in Wordle's current row and edit a single letter before submitting (TODO Task 29).
- Changed Wordle's score to reflect guess count directly (lower is better) instead of a points value (TODO Task 21).
- Fixed the homepage game-grid's columns drifting to unequal widths across rows and screen sizes; now a real CSS grid (TODO Task 25).
- Fixed the Game Menu's divider showing above an empty action-button area when a game had no action buttons yet (TODO Task 30).

## 2026-08-25

- Fixed Word Hive's center hexagon showing a gray border instead of blue along its bottom-left/bottom-right edges, caused by the row below painting over it -- the center cell now always paints above its neighbors.

## 2026-08-24

- Fixed Word Hive's honeycomb hexagons only showing a border on the center cell and reading as overlapping -- each cell is now a real SVG polygon instead of a clip-path'd box, so every edge (including the diagonals) actually renders a border.
- Fixed the room-name field overlapping the button below it in the site menu drawer.
- Fixed the game menu button rendering taller than the fullscreen toggle button next to it.
- Added a host-only "End Game" action to Punchline, Yahtzee, Best Match, and Clear the Deck (Boggle, 10,000, and Survey Says already had one).
- Fixed the "Host is setting up..." room notification only being visible while the site menu was open; its link (and the "game started" notification's link) now goes straight into the game instead of the room hub.
- Removed the "play other games" buttons from each game's own setup/lobby page -- switching games is now the room hub's job only.
- Restricted Punchline's "Next Round" button to the host.
- Added a "Resume your seat"/"Continue setup" link to each room-capable game's home page card, for a signed-in user already in a room for that game.
- Redesigned Word Hive's letter input into a real tessellating honeycomb layout, with the key letter centered.
- Added per-word-length "found/total" progress columns to Word Hive, e.g. "4 Letters (5/11)".
- Fixed the actual root cause of Punchline's vote button redirecting to the homepage: in a 3-player game, every matchup includes every seat, so a voter had no way to visually tell their own anonymized answer apart from anyone else's -- clicking it by accident hit the server's real "no self-votes" rule and got treated as an error. Vote options now exclude the viewer's own answer, and a self-vote that somehow arrives anyway is silently dropped instead of erroring the whole vote.
- Fixed Punchline's "Next Round"/"See Final Results" button also redirecting to the homepage when two players clicked it at once, and a stale click on a matchup from an already-advanced round hard-404ing instead of quietly no-opping -- the same staleness fix as the answer/vote actions, applied to the one remaining action that had been missed.
- Fixed rooms silently expiring mid-game and 404ing for every player, for every room game (not just Punchline) -- a room's expiry was fixed at creation time and never extended by activity; now extended automatically on every real game-state update via a single new listener.
- Fixed Punchline sometimes redirecting to the homepage when submitting an answer or casting a vote, caused by validating against a stale client-side snapshot instead of the current database state; both actions now re-sync and quietly no-op on a benign race instead of erroring. Increased the writing timer from 75s/150s to 120s/240s after real-play feedback found it too short.

## 2026-08-23

- Fixed Word Blocks' `.quartile-tile` buttons rendering oversized due to a `<button>` padding-vs-sizing bug (found while building Word Hive), via a new shared `.tile-btn` CSS class also adopted by Word Hive's own letter buttons; added a browser geometry regression test.
- Added Punchline, a new Quiplash-style multiplayer room game: players privately write funny answers to prompts across a fixed 3-round game (2 head-to-head pairing rounds plus an everyone-answers-the-same-prompt final round), vote, and score by proportional vote share with an escalating point pool and a sweep bonus. Online rooms only. New `punchline_prompts` bank (153 prompts), `PairingService`/`ScoringService`/`BallotService`/`FillerAnswers`, profile stats, and homepage/sidebar wiring.
- Added a color/mark legend above Five Letters' guess grid explaining what each tile's color and corner mark mean; fixed the grid rendering at the wrong size due to a stale Tailwind build.
- Excluded slurs, profanity, and words tied to slavery, violence, sexual content, and self-harm from `CommonWordFilter`'s answer pool via a new curated `storage/app/excluded_words.txt`, so Five Letters (and, later, Word Blocks) never picks one as a puzzle answer.
- Split `docs/TODO.md`'s Task 10 into three (Pips/Spelling Bee, Connections/Strands, and the Word Blocks `CommonWordFilter` follow-up) and moved all 10 items from `docs/concerns.md` into `docs/TODO.md` as Tasks 21–30, each with concrete file/method references.
- Resolved the skeleton specs for Word Hive (Spelling Bee) and Pips into full build specs (`docs/specs/spelling-bee.md`, `docs/specs/pips.md`).
- Added Word Hive as a new solo daily/random puzzle game: spell words of 4+ letters from 7 given letters including a required center letter, with a rank ladder, pangram bonus, resumable in-progress games, a shared/Challenge-a-Friend seed link, and profile stats. Wired into the daily-puzzles archive, homepage, and sidebar. Added `CommonWordFilter::excluding()` to reuse the sensitive-word exclusion list without its frequency ranking.

## 2026-08-22

- Added Wordle as "Five Letters": daily/random 5-letter word guessing, hard mode, a shared/ranked Challenge a Friend link, and profile stats. Wired into the daily-puzzles archive, homepage, and sidebar.
- Added a shared `CommonWordFilter` word-commonness service, backed by a new `storage/app/word_frequency.txt` corpus, so Five Letters' daily/random answer is always drawn from common words rather than the full dictionary.
- Added skeleton build specs for Pips, Spelling Bee, Connections, and Strands (`docs/TODO.md` #10).
- Moved Home and Rooms out of the top bar into the Menu drawer; the Rooms dropdown's own toggle/panel is gone, its content (room list, Join/Create a Room) now renders directly in the drawer.
- Changed the Menu button to a hamburger icon instead of the word "Menu", and relabeled "Game Menu" to "Game".
- Fixed the Game menu covering the score/timer HUD on mobile game pages by converting it from a dropdown into a proper modal dialog.

## 2026-08-21

- Redesigned the top navigation bar: it's now a fixed, small set of items on every page (Home, Menu, Rooms, Game Menu, avatar) that never wraps on mobile, replacing the old per-page mix of buttons.
- Generalized the stats-only sidebar into a site-wide "Menu" drawer (Daily Puzzles, stats, Friends, About, Privacy, guest sign-in links), reachable from every page.
- Added a "Game Menu" dropdown holding Rules and each game's own action buttons (End Game, Reveal, Watch only, etc.), replacing the loose buttons that used to sit directly in the bar.
- Trimmed the navbar avatar dropdown to Edit Profile and Logout only; Stats and Friends moved into the Menu drawer.
- Provisioned the missing Laravel Cloud queue worker in production — friend-request and Challenge-a-Friend emails were queuing correctly but never actually sending, since no worker had ever been running.
- Added a crop-before-upload step to avatar uploads, using Cropper.js.
- Raised the avatar upload size limit from 2MB to 10MB, and fixed nginx/php-fpm upload limits that were silently capping it below the old Laravel-side limit.
- Fixed the daily puzzles feature being unreachable: added a site-wide "Daily Puzzles" nav link, a prominent "play today's puzzle" card on the archive page, and an in-play badge showing when a game is a daily (and which date).
- Added shared puzzle-game infrastructure: puzzle-code sharing for Word Blocks, daily puzzles with a 30-day archive for Word Grid and Word Blocks, and emailed Challenge-a-Friend invites from both games' reveal screens and the Friends page's activity feed.
- Removed the analytics time-on-site/time-per-page TODO item.
- Removed the pending-friend-request badge dot from the avatar toggle itself; it now only shows next to "Friends" inside the dropdown panel.
- Added state/region-level analytics: switched the geo database from MaxMind's GeoLite2-Country to GeoLite2-City and added a "Regions" panel to the analytics dashboard.
- Split the dashboard/profile Boggle "avg score" stat into separate solo and rooms figures instead of blending two different score scales together.
- Added a Word Grid stat: points earned from words over 4 letters, shown per-game and as a personal best.
- Added a pending friend-request count badge to the navbar avatar dropdown.
- Friend requests now send the recipient an email notification.

## 2026-08-20

- Fixed the navbar avatar rendering as an oval instead of a circle on real mobile-width viewports (e.g. the homepage, which always sets `manyButtons`) — two unlayered CSS rules were silently overriding the avatar toggle's own zero-padding with horizontal-only padding.
- Removed Word Blocks' Restart button and its `restartGame()` action; New Game is now only shown once the game has ended, not throughout play.
- Word Blocks: dropped the per-found-row "Quad found" label; grid + controls and the found-words list now sit side by side on desktop instead of one stacked column.
- Word Blocks: fixed a bug where a found Quad sometimes silently didn't register when two tiles showed identical text — the puzzle generator now guarantees every tile's displayed text is unique.
- Word Blocks: added resumable in-progress games for signed-in users (auto-resume on visit, Restart/New Game/End Game buttons), a live found-words list during play; fixed the navbar avatar's position and shape being affected by other pages' own button-bar buttons.
- Added Word Blocks (Quartiles): a solo word puzzle where players combine 2-4 letter tiles into words, with algorithmically generated tiles and 5 hidden "Quads" for bonus scoring.
- Added friends: mutual friend requests, a friends list, and a friend-activity feed.

## 2026-08-18

- Reverted the "Home" → "Play" button rename from earlier today ("Dashboard" → "My Stats" stands); fixed the now-stale test this desynced.

- Fixed `docker compose exec app php artisan test` failing/hanging: the Docker PHP image was missing `ext-sockets`, `ext-intl`, and JPEG support in `gd`, all added since the image was last rebuilt.
- Changed Clear the Deck: a forced pickup now ends the turn immediately, passing to the next seat, instead of granting the acting player an extra turn.
- Fixed Farkle: three-of-a-kind 1s now score 300, not a special-cased flat 1000 — as a side effect, four 1s now scores the flat four-of-a-kind value (1000) instead of 1100, matching every other face.
- Added a `/privacy` page (linked from the site footer) covering account data, cookies, analytics (the drafted copy from `docs/specs/analytics.md`), and the third-party services this app uses.
- Added image avatars: upload a picture (stored on a real S3-compatible bucket) or remove it, from the profile page; `<x-avatar>` renders it wherever avatars already appeared.
- Renamed the persistent "Home" button to "Play" and "Dashboard" to "My Stats" everywhere they appear (top bar, sidebar, page title).
- Enabled two-factor authentication: login challenge page, and an enable/confirm/view-recovery-codes/disable panel on the profile page.
- Fixed Farkle's dice row silently over-growing past its intended size on a wide screen, the same `flex-grow` bug already fixed in Yahtzee's own dice row.
- Audited every teleported button-bar action across all six games for real click-through test coverage (prompted by the Boggle bug below); added the 10 missing ones across Yahtzee, Farkle, Family Feud, Clear the Deck, and Best Match. All confirmed already working — no second hidden bug found.
- Fixed the "New Game" button at the end of a solo Boggle game doing nothing at all; root cause was a missing wrapper div inside Boggle's `@teleport` block, the one game in the app missing a pattern every other game's own teleported button-bar content already used.

## 2026-08-17

- Fixed Boggle recording a solo game to stats when "End Game" was clicked to stop a timed game early, before its own clock ran out; untimed games and naturally-expired timed games still record as before. Reveal screen now shows a "not saved" message for an early-ended game.
- Bumped Farkle's live (non-spectator) and final scoreboard font from a flat 22px to a `clamp()` reaching up to 32px on a large screen, matching the dedicated spectator scoreboard's own earlier TV-legibility bump.
- Made Clear the Deck's spectator-view current-player highlight easier to read on a TV: a new dedicated `--ctd-active` color (not the shared `--accent`), a thicker border, and a "▸" marker on the active player's name.
- Verified Family Feud's and Best Match's spectator views against the same no-scroll requirement Clear the Deck/Farkle/Yahtzee already met (real seeded rooms at a full 8-player room, 1920x750 TV-cast viewport); confirmed clean, no fix needed.
- Fixed a real bug in Yahtzee's dice row silently over-growing past its intended size on any sufficiently wide screen (worst on a short/wide TV-cast viewport, where it forced the scorecard into vertical scrolling); `.yahtzee-play .row`'s `flex-grow` was the root cause, not the scorecard's own row-height floor.
- Renamed Boggle/Yahtzee/Family Feud's URL paths to `/word-grid`, `/yacht`, `/survey-says` (and their room/play/profile sub-paths), matching their already-renamed display names; route names, enums, and internal code untouched.
- Fixed a 500 crash visiting Farkle/Yahtzee/Family Feud/Best Match/Clear the Deck's play page for a room whose active session belongs to a different game (e.g. a stale tab left open after the host switched games); now redirects to the room hub instead.
- Verified analytics on the deployed app (real client IP resolution, MaxMind/admin-email env vars) and removed the now-redundant weekly `analytics:geoip-update` schedule, since it's a Laravel Cloud Build Command now and the schedule couldn't reach any replica but the one it happened to run on anyway.
- Rewrote `README.md` from scratch (standard structure: games, design principles, tech stack, project structure, local development, testing, deployment, docs) and deleted the `legacy/` static-site directory, confirmed fully ported and safely preserved in its own separate GitHub remote.
- Fixed "Back to room" on every game linking into that game's own lobby instead of the game-agnostic room hub, which could strand a straggler on a stale page after the host switched games.
- Replaced the room hub's and game lobby's forced redirect on a host game-switch with a dismissible "Join" prompt, so other viewers aren't yanked into a new game mid-thought.
- Added a global dismissible toast notification (in the navbar Rooms dropdown) when the host switches or starts a game in any room the current account belongs to.
- Added a chevron to each room row in the navbar Rooms dropdown to make it read as a link.
- Fixed Clear the Deck's spectator/TV-cast view requiring scrolling to see all players at high player counts, and moved the "On the table" pile above the seat grid in that view.
- Fixed the same never-fits-without-scrolling bug in Farkle's and Yahtzee's own spectator/TV-cast views.
- Fixed two CSS custom properties (`--text-label`, `--tracking-label`) that were never actually defined, silently leaving Clear the Deck's spectator names at an unstyled default size.
- Moved `docs/concerns.md`'s remaining items into `docs/TODO.md` as new tasks with clearer descriptions, then cleared `concerns.md`.
- Root-caused and confirmed reproducible a crash reported in `docs/notes.md` (visiting a game's play page for a room whose active session belongs to a different game); added as a new `docs/TODO.md` task rather than fixed. Cleared `notes.md`.

## 2026-08-16

- Added first-party cookieless analytics with an admin dashboard at `/analytics`: salted daily-rotating visitor hashes, a recording middleware + queued job pipeline, hourly rollups/nightly pruning, and an env-gated dashboard.
- Fixed the analytics dashboard crashing on some app workers after deploy by caching only plain arrays/Collections in `AnalyticsRepository`, never app-defined classes, removing all object (de)serialization from the cache layer.
- Added y-axis reference numbers to the analytics dashboard's timeseries chart, which previously had no numeric scale.

## 2026-08-15

- Fixed the site footer appearing to rise over content mid-scroll on tablet landscape -- switched `body`'s `min-height` to `100dvh` and forced independent compositing on `.site-footer`.
- Fixed Yahtzee's sticky category column overlapping the current-player column on mobile once the scorecard overflows `.card-wrap` -- `align-items: safe center` instead of plain `center`.

## 2026-08-13

- Added a TV-cast/screen-share spectator view to Clear the Deck, mirroring Yahtzee's own "Watch only" toggle.
- Added an optional guest display name when joining/hosting a room, remembered via a cookie for later direct-link visits.
- Reworked Yahtzee's undo to be opt-in, room-capable, and roll-only, with a confirm-before-score dialog replacing its old ability to also revert a score.
- Yahtzee's scorecard now always shows every player's column on every viewport, replacing the old mobile single-seat view and separate scores drawer.
- Yahtzee now shows live possible scores to every viewer during the current player's turn, not just that player.
- Renamed "Clear the Deck" to "Clear the Deck (Tens)" everywhere it appears.
- Fixed the "Back to Room" button overflowing off-screen on mobile -- a shared button-bar CSS gap affecting every game, not just one.
- Suppressed the raw error flash when a room ends mid-game via a global Livewire request interceptor.
- Added on-brand 403/404/419/429/500/503 error pages using the app's real layout.
- Reworked Clear the Deck's face-down flip from a single blind/atomic submission into a two-step "reveal to you, optionally add matching cards, then Confirm" flow, matching the physical rules more closely -- the previous all-at-once design read as broken once actually played.
- Fixed Clear the Deck matching the mockup more closely: Submit now sits beside the pile instead of below it; opponent tableaus always show 4 column slots instead of shrinking as cards are played; "On the table"/"Your columns"/"Your hand" are now bold heading-styled labels; opponent names are bold; the mobile hand grid is a fixed 4-column grid instead of reflowing like desktop. Removed the "Round X of Y is dealing" status line and last-action message (no mockup counterpart); opponent line now shows hand count only, no score. Fixed a real bug where tapping one face-down card selected every uncovered face-down card at once, replacing the underlying "range select" with an independent per-tile toggle.
- Restyled Clear the Deck's play page with real playing-card visuals (hand, table columns, fanned center pile), imported from a Claude Design mockup via the `claude_design` MCP tool: new `<x-clear-the-deck-card>` component, a `columnsFor()` display-only helper reconstructing table columns from the aggregate face-up/face-down counts, and tap-to-select face-down flipping in place of the old stepper. Fixed a real layout bug found along the way (a positioned wrapper whose own `calc()`-based size depended on a CSS custom property that was only ever set on its children, never on itself).
- Added Clear the Deck, a new online-rooms-only turn-based shedding card game for 3-10 players: `GameType::ClearTheDeck`, two new nullable `room_seats` columns (`table_face_up`/`table_face_down`), `pages::clear-the-deck.room`/`play` Livewire pages with the full turn engine (sets, wild Tens, pickups, multi-round scoring), `ClearTheDeckStatsService` + profile stats pages, and homepage/sidebar wiring.
- Filled in `docs/specs/clear-the-deck.md`'s open design questions (round length, set eligibility, wild Ten behavior, name) per user decisions; spec is now ready to build from.
- Fixed two pre-existing `match (GameType)` expressions (`profile.games.index`'s `showRoute()`, `<x-player-stats>`) that had no `AppleMatch` arm and would have thrown for any Best Match row.
- Restyled Best Match's non-judge round-reveal from plain text to card-styled boxes, matching the judge's own button-styled view.
- Changed `docs/TODO.md`'s task numbers to be permanent IDs that never get renumbered on completion, separate from the Order of Work list's own position numbers; moved every `docs/concerns.md` item into `docs/TODO.md` as new tasks and emptied `concerns.md`.

## 2026-08-12

- Fixed a room member having no way to discover a game the host is setting up but hasn't started yet (only reachable before by being on the room hub at the exact broadcast instant) -- added `Room::markPendingGame()`/`pendingGameType()` and a "host is setting up X" join link on the room hub, wired into all five games' own room pages.
- Fixed Best Match's room page silently doing nothing when the host tried to start with fewer than 3 players; now shows a real error message instead.
- Added Best Match, a new online-rooms-only card-matching game (an "Apples to Apples"-style reinterpretation): `GameType::AppleMatch`, `apple_match_decks`/`apple_match_prompts`/`apple_match_cards` tables + a seeded default deck (~111 prompt cards, ~203 answer cards), a nullable `room_seats.hand` column, `pages::apple-match.room`/`play` Livewire pages, `AppleMatchStatsService` + profile stats pages, and homepage/sidebar wiring.
- Filled in `docs/specs/apple-match.md`'s open design questions (name, online-only scope, static card content, player count, scoring, hand size) and its data model, including forward-compat for a still-deferred custom/AI deck-editor idea from `docs/notes.md`.
- Changed Farkle's URL paths from `/farkle` to `/10000` (and `/profile/farkle` to `/profile/10000`), keeping route names, view names, and everything else internal unchanged.
- Skipped the "busted" → "Farkled" concerns.md item (reverts a prior trademark-avoidance decision); no code changed.

## 2026-08-11

- Fixed five more Farkle layout bugs: banked dice now render below the main dice row in one row per roll instead of a single row above it; the banked-dice row no longer gets cut off on narrow mobile; the desktop live scoreboard font is larger; the button-bar wraps instead of overflowing off-screen in a crowded online-room game (plus two follow-on bugs found live: a stretched Rules button, insufficient reserved top padding); and body/button-bar/site-footer now account for `safe-area-inset-left/right` for landscape camera-notch handling.
- Added a dedicated Farkle mobile-landscape layout (dice column and scoreboard side by side, mirroring Boggle's own landscape layout) fixing two bugs: Roll Again/Bank overlapping the last die, and the scoreboard having too little height to reach every player.

## 2026-08-06

- Fixed six Farkle mobile/UX bugs found in real play (renamed "Farkle" to "10,000" for trademark, mobile scoreboard scroll, banked-dice single-row layout, portrait dice overlap, desktop score font size, and "Back to Room" doing nothing on game over in all four games). Made rooms a persistent, cross-game feature: new `RoomMember` table, a room hub page (`/room/{code}`), and a navbar "Rooms" dropdown for creating/joining rooms from anywhere; each game's setup screen now shows a "rooms you host" shortcut instead of its own Host/Join UI.
- Fixed the room hub's "Start a new game" not actually letting anyone but the host into the game (now broadcasts the same event `GameLobby::switchGame()` uses, so other members follow along); fixed the navbar Rooms dropdown positioning off-screen on mobile; fixed the dashboard's mobile menu having a transparent background (a CSS custom property was never actually defined).

## 2026-08-05

- Fixed the same stale-`isMyTurn` bug in Family Feud's submitGuess/retryGuess/skipQuestion/swapControl (found while auditing after the Farkle fix); added a regression test verified against the pre-fix code.
- Fixed Farkle's scoring table (flat 4/5/6-of-a-kind values, plus straight/three-pairs/two-triplets), made Bank auto-include every scoring die, fixed several UI legibility/layout-jump issues, and fixed a real Livewire computed-property staleness bug in the Roll Again/Bank buttons.
- Added Farkle: a new dice game with standard scoring, hot dice, a configurable target score/final round, and both pass-and-play and online rooms at launch.
- Answered docs/specs/farkle.md's open design questions (scoring, win condition, modes, hot dice) per user decisions; spec is now ready to build from.
- Renamed Boggle, Yahtzee, and Family Feud to Word Grid, Yacht, and Survey Says everywhere a user can see the name, to avoid trademark issues; routes/enum values/class names/CSS classes left unchanged.
- Added a daily-scheduled `users:prune-guests` command that deletes guest accounts older than 24 hours.
- Allowed hosting online rooms while logged out (auto-provisions a guest account, same as joining already did); removed the real-account gate from all three setup screens' Host button.
- Decoupled online rooms from specific games (switch games mid-room via new "or play something else in this room" buttons, same code/seats); also fixed a real pre-existing bug where a genuine room replay 500'd on a UNIQUE constraint violation.
- Added a persistent sidebar (Dashboard/All Games/Boggle/Yahtzee/Family Feud Stats/Edit Profile/Log Out) covering the Dashboard/Profile/Stats pages, replacing each page's own inconsistent Dashboard-button/back-link; off-canvas panel on mobile.
- Defaulted "Remember me" to checked on password login (matching passkey logins) and raised SESSION_LIFETIME to a year; users should now stay signed in for a long time instead of hitting a 2-hour idle cliff.
- Rewrote the Family Feud homepage card's description to explain the actual gameplay instead of the AI-judge implementation detail.
- Fixed the remaining stats tables not scrolling on mobile (Yahtzee/Family Feud All Games tables, dashboard/public-profile recent-games list); added real-browser scroll-overflow tests.
- Added a Result stat tile to the individual Boggle game page (omitted, not a dash, for solo games); Yahtzee/Family Feud already had this.
- Added a win/loss Result column to Boggle's All Games table (dash for solo games); also gave the now-wider table horizontal scroll on mobile instead of squashed columns.
- Added a paginated "All Games" stats page (`/profile/games`), listing every game across all types for the acting user, linked from the dashboard.
- Fixed Boggle's dashboard win-rate calculation: restricted to online-room games, excluding any session where every participant scored zero; games played/avg score untouched. Added test coverage.

## 2026-08-04

- Fixed a real duplicate-`GameParticipant`-row bug in shared Boggle sessions (mount() didn't restore already-finished state, so a reload allowed a replay); cleaned up the two confirmed-stale rows in the dev database; added regression tests.
- Added a "Delete Account" button (Danger Zone panel on `/profile`), gated behind `<x-confirm-dialog>`; hard-deletes the user (schema-native cascade/null-on-delete), logs out, redirects home. Added Feature + browser test coverage.
- Added a "Clear All Stats" button (Danger Zone panel on `/profile`), gated behind `<x-confirm-dialog>`, that deletes only the acting user's own `GameParticipant` rows; added Feature + browser test coverage.
- Added reusable `<x-dialog>`/`<x-confirm-dialog>` Blade components (generic delegated open/close JS in `app.js`) and replaced Family Feud's three `wire:confirm()` uses (Skip Question/End Game/Reveal) with them; added `tests/Browser/FamilyFeudConfirmDialogsTest.php`.
- Rewrote `docs/specs/clear-the-deck.md` from a plain rules pastebin into a build spec matching the Farkle/Apples to Apples format (open design questions, data-model notes, accessibility), with the original rules kept verbatim in an appendix; added it to `docs/TODO.md` as #20.
- Reorganized `docs/TODO.md` into a numbered index + one heading per task, ordered by dependency; added `docs/specs/farkle.md` and `docs/specs/apples-to-apples.md` as skeleton specs with open design questions rather than invented rules.
- Added the Games/Smaller Items sections to `docs/TODO.md` (Apples to Apples, Farkle, online-rooms decoupling, anonymous room hosting, stats fixes/additions, navigation rework, Edit Profile clear-stats/delete-account, `wire:confirm` replacement, guest cleanup, 2FA).

## 2026-08-02

- Added a host-only team-assignment screen between "Start Game" and the first round for Family Feud online rooms: `onRoomStarting()` no longer creates the session immediately, instead snapshotting ready players into an editable default (alternating) team/captain split; `moveToTeam()`/`makeCaptain()` let the host reshuffle before `startRound()` finalizes it, gated on both teams having at least one player.
- Added online rooms for Family Feud (team/captain assignment, rotating turn pointer, captain-only steal gating, per-seat authorization on every mutating action, per-seat `GameParticipant` attribution) and new `/profile/family-feud` stats pages.
- Fixed a Yahtzee room bug where a seated player's own "Watch only" view still showed the live possible score for their open categories; added the current roll status and dice to the mobile Scores drawer.
- Replaced the two now-implemented Yahtzee room TODO items with the "Smaller Items" section heading (renamed from "Smaller, previously-flagged gaps").
- Regenerated `package-lock.json` to fix a Laravel Cloud build failure (`npm ci` missing a platform-specific `@emnapi/runtime@2.0.0-alpha.3` entry pulled in by Vite 8's Rolldown bundler).
- Added Yahtzee browser tests: full solo playthrough, mobile dice/Roll layout, and online-room hosting/joining/seated-play/spectator/mobile-drawer coverage; documented two `pest-plugin-browser` gotchas (`on()->mobile()` dropping clicks, concurrent `visit()` pages hanging) in `docs/DEVLOG.md` and `CLAUDE.md`/`AGENTS.md`.
- Installed Pest browser testing (Playwright) and the `--agent` verification probe; added a `Browser` testsuite and two real browser tests; documented both in `CLAUDE.md`/`AGENTS.md`.
- Split the old phase-organized `docs/CHANGELOG.md` into `docs/DEVLOG.md` (feature-organized) and this terse commit log; documented both files plus `docs/TODO.md` in `CLAUDE.md`/`AGENTS.md`.
- Yahtzee: kept the mobile scoreboard drawer open across live state updates from other players (`wire:ignore.self`); widened scorecard columns on desktop and moved the Roll button onto the same row as the dice, shrinking the dice to fit.
