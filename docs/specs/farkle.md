# Farkle — Build Spec

Status: **ready to build from.** Open design questions (below) were answered by the
user on 2026-08-05. See `docs/TODO.md` #1.

---

## 1. Core design decision (read first)

Farkle is a solo/turn-based dice game, structurally closer to this app's existing
Yahtzee than to Family Feud: no hidden per-player information, no judge, just dice,
scoring, and turn order. Yahtzee's implementation (`resources/views/pages/yahtzee/`,
`app/Services/Stats/YahtzeeStatsService.php`, its pass-and-play + online-room pages) is
the closest existing reference and should be used as the starting template rather than
designing this from scratch. Unlike when Yahtzee first shipped, online rooms are now a
generic, game-decoupled system (see `docs/DEVLOG.md`'s "Room system" section and
`GameType::roomRoute()`), so Farkle should build its online-room support directly
against that existing system rather than as a one-off.

## 2. Design decisions

- **Scoring (standard variant, no straight/3-pair bonus):**
  - Single 1 = 100 points. Single 5 = 50 points.
  - Three of a kind: 1s = 1000; any other number `n` = `n × 100` (e.g. three 4s = 400).
  - Four of a kind = 2× the three-of-a-kind value for that number. Five of a kind = 4×.
    Six of a kind = 8×.
  - No bonus for a 1–6 straight or three pairs in one roll — not part of this ruleset.
  - A roll that scores nothing at all is a Farkle: the turn ends immediately and the
    entire turn's unbanked score is lost (not just discarded down to what was already
    banked this turn — the whole turn is zero).
  - **Minimum 500 points to get on the board.** A player's running total stays at 0
    until a single turn banks at least 500 in one go; that turn's full score then
    counts. Every turn thereafter, points bank normally regardless of amount.
  - **Hot dice**: if every one of the six dice has scored (across one or more rolls
    within the same turn), the player may roll all six again and keep adding to the
    same turn's running total instead of being forced to bank.
- **Win condition:**
  - Target score is **10,000 by default**, but configurable per-game at setup time
    (same setup-screen pattern Boggle uses for board size/timer).
  - **"With Final Round" toggle, defaulting to on.** When enabled (classic rule): the
    moment a player banks a turn that brings their total to the target or above, every
    other player gets exactly one more turn, then the highest total wins. When
    disabled: the game ends immediately the instant any player crosses the target —
    no final round for the others.
  - **Manual "End Game" control**, mirroring Family Feud's `endGame()` pattern (a
    confirm-dialog-gated button, not silently ending on click) so a game can be cut
    short before the target is reached. In an online room, restricted to the room's
    host (`Auth::id() === $room->host_user_id`) — a non-host seated player does not see
    the button, matching how Family Feud gates its own host-level actions where noted
    in `pages::family-feud.play`. In pass-and-play (no room), any player can end it,
    matching Family Feud's own `$room === null` local-play carve-out.
- **Modes: pass-and-play and online rooms, both at launch.** Pass-and-play is one
  device handed off between players each turn (matching Yahtzee/Family Feud's existing
  local mode). Online rooms use the existing decoupled room system —
  `GameType::Farkle->roomRoute()` → a `pages::farkle.room` page — rather than a
  one-off build.
- **Turn UI shape:** Roll → the player taps/selects which scoring dice to keep (must
  select at least one scoring combination from the roll to continue, otherwise it's a
  Farkle) → Bank (adds the turn's running total to their overall score, turn passes to
  the next player) or Roll Again (re-rolls the remaining, not-yet-held dice). This
  matches Yahtzee's existing "roll → act → confirm" rhythm closely enough to reuse the
  same general interaction pattern, just with dice *selection* instead of *category*
  selection as the scoring action.

## 3. Data model

Mirrors Yahtzee's shape:
- A new `GameType::Farkle` enum case (`label()` → a non-trademarked-safe name is not
  needed here — "Farkle" itself is not a live trademark concern the way Boggle/
  Yahtzee/Family Feud were, per `docs/DEVLOG.md`'s "Game renaming" section — but
  double-check before shipping copy just in case genericized-trademark status has
  changed).
- `GameSession.state` (JSON) holds in-progress state: player list (name/user_id or
  guest name, matching Yahtzee's `meIndex` pattern for "which local player is you"),
  current player index, current dice values, which dice are held/scored this roll,
  running turn score, per-player banked totals, the game's configured target score and
  "with final round" flag, and (once triggered) which player crossing the target
  started the final round and how many final turns remain.
- A `FarkleStatsService` in `app/Services/Stats/`, following
  `YahtzeeStatsService`/`BoggleStatsService`'s existing pattern (`statsFor()`,
  `gamesForUser()`, `bestMetrics()`, wired into `<x-player-stats>` and a
  `profile.farkle` index/show page pair).
- `GameType::roomRoute()` gains a `'farkle.room'` mapping; a `pages::farkle.room` page
  is added following `pages::yahtzee.room`'s structure (seat assignment via
  `RoomSeat`, `onRoomStarting()` writing the initial `GameSession.state`).

## 4. Accessibility

Follows the same low-stim design system as every other game in this app (see
`docs/DEVLOG.md`'s "Site-wide design system & chrome" section) — muted palette, no
flashing/bouncing dice animation, no autoplay sound, respects `prefers-reduced-motion`.
No new accessibility decisions expected here beyond applying the existing system.

## 5. Deliverables for v1

- `App\Enums\GameType::Farkle` case + `label()`/`roomRoute()` entries.
- `App\Services\Farkle\*` for scoring/turn logic (mirroring
  `App\Services\Yahtzee\{DiceRoller,GameEngine}` — pure, testable scoring functions
  separate from the Livewire component).
- `App\Services\Stats\FarkleStatsService`.
- Livewire pages: `pages::farkle.lobby`/`play`/`room` (pass-and-play setup, the actual
  game, and the online-room seat/start screen), following Yahtzee's file layout.
- Profile stats pages: `pages::profile.farkle.{index,show}`.
- Homepage card (`resources/views/welcome.blade.php`), sidebar link
  (`resources/views/components/app-sidebar.blade.php`), `<x-player-stats>` wiring.
- Feature tests for scoring/turn logic and stats, Browser tests for the real click
  through a full turn (roll → select → bank/Farkle) and the online-room flow, per this
  app's existing testing conventions (`CLAUDE.md`'s "Automated Browser Testing"
  section) — this is a large, stateful new feature, so test thoroughly rather than
  minimally.
- `docs/DEVLOG.md`/`docs/TODO.md`/`docs/CHANGELOG.md` updated per this repo's standing
  documentation convention.
