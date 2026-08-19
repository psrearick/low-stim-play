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

- **Scoring, corrected 2026-08-05 per `docs/concerns.md` (supersedes the original
  "doubling" variant this shipped with, which was wrong), and three 1s corrected again
  2026-08-18 per `docs/TODO.md`'s former #22:**
  - Single 1 = 100 points. Single 5 = 50 points.
  - Three of a kind: 1s = 300 (the same 100-per-die value as one or two 1s — there is no
    special three-1s bonus); any other number `n` = `n × 100` (e.g. three 4s = 400).
  - Four of a kind = 1000, five of a kind = 2000, six of a kind = 3000 — **flat values,
    not scaled by which face is repeated** (four 4s = 1000, not 800; four 1s and four
    6s score the same 1000 base). Whichever way of scoring a given roll is worth more
    is always used automatically: four 4s is scored as the flat 1000, since that beats
    three 4s (400) plus one leftover "single" 4 (0, only 1s/5s score individually);
    four 1s is likewise scored as the flat 1000, since that now beats three 1s (300)
    plus one leftover single 1 (100) = 400.
  - **Three more special patterns, each using all 6 dice in a single roll**: a straight
    (one each of 1–6) = 1500; three pairs (e.g. 2-2, 4-4, 6-6) = 1500; two separate
    triplets (e.g. three 2s and three 5s) = 2500. These replace the normal per-face
    decomposition entirely for that roll rather than adding to it.
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
- **Turn UI shape:** Roll → Bank (auto-includes every scoring die in the current roll
  and ends the turn, regardless of what's manually tapped — see `docs/concerns.md`:
  clicking Bank should never leave un-clicked scoring dice off the total) or, to keep
  going, tap specific dice to lock in and press Roll Again (re-rolls only the
  remaining, not-yet-held dice; Roll Again *does* require an explicit, valid manual
  selection, unlike Bank). This matches Yahtzee's existing "roll → act → confirm"
  rhythm closely enough to reuse the same general interaction pattern, just with dice
  *selection* instead of *category* selection as the scoring action.

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

## 6. Spicy variant (optional toggle) — added 2026-08-19, see `docs/TODO.md`'s former #23

Rules as given (verbatim from the TODO entry):

- Each of the 6 dice has one face marked in red — a distinct number, one per die, so
  there's exactly one red ("spicy") side for each of the numbers 1 through 6 across the
  six dice.
- Rolling a spicy die doubles the score of whatever scoring combination it's part of.
  E.g. `[4, 4, spicy 4]` scores 800, not the normal 400 for three 4s.
- Multiple spicy dice within the same combination each double it again:
  `[4, spicy 4, spicy 4]` scores 1600, not 400.
- A spicy die's doubling applies only to the combination it's rolled as part of in that
  specific roll — it does not retroactively apply to dice already locked in/frozen from
  an earlier roll this turn.
- **High Stakes** (a separate, additional toggle, independent of Spicy — can be on or
  off regardless of whether Spicy itself is enabled): a player may open their turn by
  rolling the *previous* player's own non-frozen dice, instead of a fresh six. If they
  score on that first roll, the points are added to the previous player's own turn
  score (not a fresh turn total), and they may continue rolling if they choose. This
  inheritance is available even if the previous player ended their turn by banking with
  all six dice already used, not only if they stopped mid-turn with dice still unused.
- Default target score for a Spicy game is 25,000, not the normal mode's 10,000 (still
  host-configurable via the existing target-score setting, just a different default
  when Spicy is on).
- Rolling all six dice as spicy in a single roll is an instant win, regardless of the
  score reached.

### 6.1 Digital-equivalent for "one red face per die"

There's no real per-physical-die identity anywhere in the current model — `DiceRoller`
just returns `N` independent `random_int(1, 6)` values, and `Scorer` only ever sees
face *counts*, never which specific die produced which face. Reusing that same
"anonymous dice" model doesn't work here: a spicy face is a property of a specific
physical die (die A's red face is always "1", die B's is always "2", etc.), and once a
player starts setting some dice aside mid-turn, "is this face spicy on this reroll?"
depends on *which* physical dice are still in the cup, not just how many.

Digital equivalent (no open question — this is just how six real dice map onto data,
same as Clear the Deck's own deck-count translation in §2 above): number the six
physical dice 1–6 and fix die *N*'s red face at value *N* (die 1's red face is "1", die
2's red face is "2", …). This is an arbitrary but harmless labeling — real dice are
otherwise interchangeable and every face is equally likely regardless of which die
shows it, so this fixed assignment is statistically identical to any other 1-to-1
mapping. A die is spicy on a given roll exactly when its rolled value equals its own
label.

State needed: an `activeDieSlots` list (the labels, 1–6, of dice not yet held/scored
this turn — starts as `[1,2,3,4,5,6]` at turn start, shrinks as dice are set aside,
resets to a full `[1,2,3,4,5,6]` on hot dice, exactly mirroring where `heldDiceCount`
already resets today). Each roll only rolls the currently-active slots; a die's spicy
flag for that roll is `value === slot`. This directly satisfies "doesn't retroactively
apply to frozen dice" for free — a held die's slot has already left `activeDieSlots`,
so it's never re-evaluated for spiciness again.

### 6.2 Doubling and the existing scoring shapes

"Combination" means whatever group of dice a face's score actually comes from:

- A lone scoring 1 or 5 (not part of a 3+ group) is its own one-die combination —
  spicy doubles just that single value (100→200, 50→100).
- Three-of-a-kind through six-of-a-kind: the *whole* matched group is one combination
  (this app's existing four/five/six-of-a-kind values are already flat, not per-die —
  see §2's scoring bullet — so this isn't a new concept, just a new multiplier on top).
  Every spicy die anywhere in that group counts toward the doubling, applied as
  `2 ^ (spicy dice in the group)`.
- The three six-dice-only special patterns (straight, three pairs, two triplets) are
  explicitly "one combination" already per §2 ("these replace the normal per-face
  decomposition entirely") — doubling is `2 ^ (spicy dice among all 6)`, same rule,
  just applied to the flat 1500/1500/2500 base instead of a per-face value.
- `bestScoreForFace()` already tries multiple candidate decompositions for a face count
  and takes whichever scores higher (e.g. four 1s as flat 1000 vs. three 1s + a leftover
  single). With doubling added, the flat multi-of-a-kind candidate provably always wins
  whenever it already would have without spicy: it captures *every* spicy die in the
  group under one multiplier, while any split candidate can only apply each spicy die's
  multiplier to whichever smaller piece it lands in. So no new per-die placement search
  is needed inside a face's candidates — computing every candidate's own doubling from
  the same "total spicy dice for this face" count and taking `max()` (the existing
  logic) still always picks the correct winner.

### 6.3 Hot dice mid-turn

No special interaction: hot dice already means "every one of the 6 dice has scored, so
`activeDieSlots` resets to all six and the player rolls a fresh set." That fresh roll is
scored exactly like any other 6-die roll — freshly re-evaluated for spiciness against
the (now-reset) slot labels, same as turn-opening roll. The "doesn't retroactively
apply to frozen dice" rule already covers this: dice scored *before* the hot-dice reset
keep whatever score they already locked in, unaffected by what's spicy on the new roll.

### 6.4 Instant win — six-spicy roll

By construction (§6.1), a die is only spicy when it shows its own label, and the six
labels are exactly 1–6. So "all six dice spicy in one roll" can only happen when the
roll's values are exactly `{1,2,3,4,5,6}` arranged so each die lands on its own label —
i.e. a specific arrangement of the already-existing straight pattern. This is
compatible with the rule as given ("regardless of the score reached") since it's checked
as its own condition before/independent of normal scoring, and short-circuits the whole
game: the rolling player wins immediately, full stop — this does **not** trigger a
"final round" for everyone else the way normally crossing the target score does (that
toggle only governs the normal target-crossing path); every other player is simply
recorded as a loss. Checked on any full 6-die roll (`activeDieSlots` has all six labels
at roll time), whether that's the turn-opening roll or a later hot-dice reroll — never
on a partial reroll of fewer than 6, since "all six" can't be satisfied by fewer dice.

### 6.5 High Stakes — resolved semantics (clarified by the user, 2026-08-19)

Only triggers off a **Bank**, never off a Farkle bust — the rule text only ever
contrasts "banking with dice unused" against "banking with all six already used," never
mentions a bust, and a bust already wipes the turn's dice/score entirely with nothing
meaningful left to hand off. So: a Farkle-ended turn always leaves the next player with
a fresh six, High Stakes or not.

When a turn ends via Bank, record what's available to inherit:
- **The dice**: whichever `activeDieSlots` were still un-held at the moment of banking
  (e.g. banked with 4 of 6 dice already held → 2 slots remain). If all six were already
  held (a hot-dice-fully-used bank), the "remaining" set is a fresh six, per the rule's
  own explicit "even if...all six dice already used" carve-out (hot dice already resets
  slot eligibility to all six, so this falls out of the same mechanism, not a special
  case).
- **The amount**: the *entire* turn score that just got banked (not just the value of
  the leftover dice) — confirmed by the user: "you also inherit all of the player's
  points, not just from those four dice but all their points from that turn —
  essentially the amount they banked becomes your starting amount."

If the next player (High Stakes enabled) chooses to take this over instead of a fresh
six: their own turn score *starts* at the inherited amount (not the previous player's
permanent total — that was already safely banked to them regardless of what happens
next), and their first roll uses only the inherited leftover dice count, rerolled fresh
(new random values — "rolling the previous player's own non-frozen dice" means the die
*pool size* carries over, not the literal prior pips: "if they bank four dice, you start
with those four dice already select[ed] and roll the two remaining dice fresh
randomized rolls"). From there it's a completely normal turn: score on this first roll
adds to that starting amount same as any other roll would, continuing to roll/bank/bust
as usual. Busting this turn loses the *entire* current turn score, including the
inherited head start — none of that risk touches the previous player's already-banked
total, which is unaffected either way. This is the actual "stakes" of High Stakes: a
free head start with real risk of losing all of it, not a windfall for the previous
player.

Offered as a per-turn choice (present only when High Stakes is on and the previous turn
ended in a Bank), not automatic — "a player *may* open their turn" this way. The
inherited-state record only lives long enough to be offered once, to the very next
player: whether taken or declined, it's cleared the moment that next turn starts (their
own turn's eventual ending — bank or bust — determines whatever is or isn't on offer
after that).

### 6.6 Data model additions

- `spicyEnabled: bool` and `highStakesEnabled: bool`, both default `false`, set at setup
  time (pass-and-play) or by the room host (online) — same toggle-button shape as the
  existing "Final Round" on/off pair.
- Toggling `spicyEnabled` resets `targetScore` to that mode's own default (10,000 /
  25,000) rather than leaving whatever was previously selected, mirroring how other
  setup fields already reset dependent state on change (e.g. `setPlayerCount()` already
  re-pads `playerNames`).
- Per-turn: `activeDieSlots` (`list<int>`, the still-live 1–6 labels) alongside the
  existing `dice`/`selected`, plus a parallel `dieSpicy` (`list<bool>`) for the current
  roll only.
- Game-level (survives across turns, not per-turn): `lastBankInheritance` —
  `null | array{turnScore: int, remainingSlots: list<int>}`, set whenever a turn ends by
  Bank (cleared on a Farkle bust), consumed/cleared the instant the next turn starts
  regardless of whether it was used.
- None of this exists, and none of these fields need to be present, when
  `spicyEnabled`/`highStakesEnabled` are both off — the normal (non-Spicy) turn flow is
  unchanged, including its existing state shape and scoring behavior.

Status: **ready to build from.**
