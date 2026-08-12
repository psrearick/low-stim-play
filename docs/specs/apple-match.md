# Best Match — Build Spec

Status: **ready to build from.** Open design questions (below) were answered by the
user on 2026-08-12. See `docs/TODO.md` #1.

**Naming:** "Apples to Apples" is an active trademark (Mattel / Out of the Box Games).
Ships under the in-app display name **"Best Match"** — a plain descriptive phrase (a
player submits their best-fitting card, the judge picks a winner), matching the
"generic phrase, not an arbitrary substitute" pattern already used for "Word Grid"/
"Survey Says" (see `docs/DEVLOG.md`'s "Game renaming" section). Internal identifiers
(enum case, routes, DB values, CSS classes, tests) stay as `AppleMatch`/`apple_match`/
`apple-match` — not user-facing, so no rename pressure, exactly as Boggle/Yahtzee/
Family Feud's internal names were deliberately left alone.

---

## 1. Core design decision (read first)

**Online rooms only for v1, no pass-and-play mode.** Unlike Family Feud (one shared
screen, no hidden information, because an LLM replaces the human judge), Best Match has
real per-player hidden information: each player holds a private hand of red (answer)
cards, and a judge role rotates each round. Pass-and-play can only fake privacy via an
honor-system "don't peek while the device is with someone else," which this app has
otherwise avoided — every other hidden-info problem here has been solved with real
per-device state, not trust. Each seated player sees only their own hand on their own
device; the shared room state (green prompt card in play, whose turn it is to judge,
how many players have submitted so far) is public. This depends on real per-player
state persisted server-side, using the same "never leak secret data into a rendered
Livewire public property" pattern Family Feud's `JudgeService`/question-bank hazard
already established (see section 3).

A local/offline mode is not ruled out forever, just deferred — add it later as its own
follow-up if wanted, once the online version has proven the card content and pacing.

## 2. Design decisions

- **Judging.** Stays a human judge, rotating each round in seat order (round-robin over
  `RoomSeat.seat_index`, advancing by one — wrapping — every time a round resolves).
  No LLM role: unlike Family Feud's answer-checking, picking a "best" card is
  inherently subjective, so there's nothing for a judge service to verify.
- **Card content.** A static, hand-curated card **set**, seeded from a JSON file
  exactly like `database/seeders/data/family_feud_questions.json` /
  `database/seeders/FamilyFeudQuestionSeeder.php` — no LLM generation, no per-draw API
  cost, fully offline-testable, deterministic across test runs. Each set has green
  prompt cards (an adjective or short phrase, e.g. "Disgusting") and red answer cards
  (a noun or short phrase, e.g. "My Uncle's Cooking"). Target at least 100 green / 200
  red cards in the one set seeded for v1 — enough that a single game's dealing doesn't
  visibly repeat.
  - **Forward-compat note (`docs/notes.md`, 2026-08-12): deliberately deferred, not
    built now.** The user wants, eventually: multiple default card sets to choose from,
    logged-in users authoring their own sets (an in-app card editor), and AI-assisted
    card generation for a theme/concept that the user then edits by hand. None of that
    ships in v1 — no editor UI, no AI endpoint, no set picker, no ownership/sharing
    policy. But it changes the *data model* now (section 3) so that adding it later is
    additive (new UI, a policy class, an AI service call), not a schema rework that
    touches every card/deal query the way retrofitting "cards belong to a chosen set"
    onto a flat global card pool would.
- **Player count.** Minimum **3** seated players (1 judge + 2 submitters — playable,
  though the game is more interesting at 4+). Enforced the same way Family Feud
  enforces its own minimum (`getCanStartRoundProperty()` gating the "Start Round"
  button and the corresponding server-side `abort_unless`), not by disabling player
  slots. No new maximum enforcement — matching Boggle/Yahtzee/Farkle's existing
  convention of not enforcing a room-size cap (`rooms.max_players` exists as an unused
  DB default of 8; this game doesn't newly wire it, consistent with every other game
  leaving it unused today).
- **Round/game length & scoring.** First-to-**N** green cards won, where N is a
  host-configurable target set on the room page before starting (same setup-screen
  pattern as Boggle's board size/timer, Family Feud's round count, Farkle's target
  score) — default **4**, adjustable 3–10. A player wins a green card by having their
  submitted red card picked by the judge that round; first to reach N wins the game.
  No separate "final round" complication (unlike Farkle) — the game just ends the
  instant someone reaches N, since there's no shared numeric total that could still
  change in another player's favor.
- **Hand size and replenishment.** 7 red cards per hand. Every seated player except the
  current judge submits exactly one red card each round; after the judge picks a
  winner and the round resolves, every player who submitted draws back up to 7. The
  judge does not submit and their hand stays untouched until they next hold a
  non-judge seat. If the deck's draw pile runs out mid-game, reshuffle every
  already-played red card (excluding cards currently in a hand) back into the draw
  pile rather than ending the game early.

## 3. Data model

- New `GameType::AppleMatch` enum case, `label()` → `'Best Match'`, `roomRoute()` →
  `'apple-match.room'`, `playRoute()` → `'apple-match.play'`.
- `room_seats` gains a nullable `hand` JSON column (list of red-card ids currently held)
  — bolted on the same way Family Feud's online-rooms work added nullable
  `team_index`/`is_captain` to the same table rather than inventing a new one, and
  nullable/defaulted so every other game's existing `RoomSeat::create()` calls, which
  set none of these, keep working unmodified.
- `GameSession.state` (JSON) holds the round-level shared state: judge seat index,
  round number, current green card id/text, phase (`Submitting`/`Judging`/`RoundEnd`/
  `GameEnd`), each seat's green-cards-won count, and the deck's own draw-pile/discard
  ordering (so a reshuffle is deterministic and resumable on reload, matching how
  Boggle's board-generation seed already makes shared state reproducible).
- **The actual "who submitted which card" attribution is the one piece of state that
  must never be assigned to a rendered Livewire public property before reveal** —
  this is the same hazard Family Feud's `JudgeService` section documents (`$this->
  questions` holding full answer rows would leak into `wire:snapshot` HTML on every
  request, regardless of what Blade actually renders). Concretely: the Livewire
  component's public properties may only ever hold (a) the acting user's own hand —
  safe, since a Livewire component instance is already per-user/per-request, same as
  every other game's own state — and (b) an anonymized, shuffled list of this round's
  submitted card texts with no seat/user mapping attached. The seat→card attribution
  needed to actually award the win is resolved via a fresh, uncached read from
  `GameSession.state` only inside the judge's own `pickWinner()` action, never held on
  `$this` beforehand. This is the concrete pattern `docs/TODO.md`'s "whichever gets
  fully specced first should set the pattern the other reuses" note (shared with Clear
  the Deck, TODO #2) refers to — Clear the Deck's own face-down-card problem should
  follow the same "never assign secret state to a public property outside the one
  action that needs it, resolve it fresh from the DB/state column instead" rule.
- Any `isMyTurn`/`isJudge`-style Livewire computed property must delegate to a private,
  **uncached** method rather than the memoized `getXxxProperty()` Livewire pattern —
  proactively avoiding the exact stale-computed-property bug class already found and
  fixed twice (Farkle and Family Feud, see `docs/DEVLOG.md`'s "Family Feud" section's
  2026-08-05 entry): an action that mutates `$phase`/judge index/etc. must never read
  its own guard condition from a memoized property computed before that mutation.
- **Three new tables, cards grouped under a deck from day one — this is the schema
  shape the `docs/notes.md` idea needs and the part that would be genuinely costly to
  retrofit later** (unlike a UI, a policy, or an AI call, all of which can be added on
  top of an existing schema without touching it):
  - `apple_match_decks`: `id`, `name`, `description` nullable, `owner_user_id` nullable
    FK to `users` (**null = a system/default deck, not owned by any user** — the only
    kind that exists in v1), `source` string/enum (`system`/`user`/`ai_generated` —
    only `system` is ever written in v1, but the column exists now so the future
    authoring/AI flow is a new value, not a new column), `generation_prompt` nullable
    text (the theme/concept a user would eventually type in for AI generation — unused,
    always null, until that feature ships), timestamps.
  - `apple_match_prompts` (green cards): `id`, `deck_id` FK (**not** nullable — every
    card belongs to exactly one deck, even the seeded default), `text`.
  - `apple_match_cards` (red cards): `id`, `deck_id` FK (not nullable), `text`.
  - No `FamilyFeudQuestion`-style "safe array" hiding needed on either card model,
    since deck *content* is never secret from anyone; only *who currently holds which
    dealt card* is (see the attribution note above).
  - `GameSession.settings` gains a `deck_id` key, resolved at room-setup time —
    hardcoded to the single seeded system deck's id in v1 (no picker UI, since there's
    only one option), but reading from settings rather than a hardcoded constant in the
    game logic itself, so a future deck-picker only has to change what writes that
    settings key, not the deal/dealing code that reads it.
  - **Deliberately not built now, and does not need to be**: any `is_public`/sharing
    visibility column, deck-editing policy, or deck-listing UI. Those are cheap
    additive changes (a new nullable column, a new policy class, a new page) once
    there's an actual second deck to choose from — unlike the deck-scoping above, they
    don't touch existing rows or existing query shapes, so there's no reason to
    pre-build them speculatively.
  - Seeded via `database/seeders/data/apple_match_prompts.json` /
    `apple_match_cards.json` + an `AppleMatchCardSeeder` that first creates the one
    system deck row, then attaches both card sets to it — mirroring
    `FamilyFeudQuestionSeeder`'s shape as closely as the extra `deck_id` FK allows.
- `App\Services\Stats\AppleMatchStatsService` in `app/Services/Stats/`, following the
  existing `statsFor()`/`gamesForUser()`/`bestMetrics()` shape (`YahtzeeStatsService`/
  `FamilyFeudStatsService`) — likely metrics: total green cards won, best single-game
  win count, games played/won. `GameParticipant.stats` gets a small payload (`green_
  cards_won`, `rounds_judged`) written at `endGame()`, matching how every other game
  already persists its own per-participant stats blob there.
- `pages::apple-match.room` follows Family Feud's own room page shape most closely
  (host-configurable settings — target N here, vs. rounds/team-names there — plus the
  nested `<livewire:game-lobby>`), not Boggle's/Yahtzee's simpler ones, since it's the
  other existing game with genuine round-based turn authority to model the judge
  rotation and per-action authorization checks after (`submitCard()` gated to "any
  non-judge seat that hasn't submitted yet this round," `pickWinner()` gated to "only
  the current judge seat").

## 4. Accessibility

Follows the same low-stim design system as every other game in this app (see
`docs/DEVLOG.md`'s "Site-wide design system & chrome" section) — muted palette, no
flashing/bouncing card-reveal animation, no autoplay sound, respects
`prefers-reduced-motion`. No new accessibility decisions expected here beyond applying
the existing system.

## 5. Deliverables for v1

- `App\Enums\GameType::AppleMatch` case + `label()`/`roomRoute()`/`playRoute()` entries.
- `apple_match_decks`/`apple_match_prompts`/`apple_match_cards` migrations + models
  (cards FK'd to a deck from day one, per section 3's forward-compat note), seeded via
  `AppleMatchCardSeeder` from two new JSON data files plus the one system deck row they
  attach to.
- `room_seats` migration adding the nullable `hand` JSON column.
- `App\Services\AppleMatch\*` for deck/deal/round logic (draw pile, reshuffle,
  judge rotation, round resolution) — pure, testable functions separate from the
  Livewire component, mirroring `App\Services\Yahtzee\{DiceRoller,GameEngine}`.
- `App\Services\Stats\AppleMatchStatsService`.
- Livewire pages: `pages::apple-match.room` (host settings, seat assignment via
  `RoomSeat`, `onRoomStarting()` writing the initial `GameSession.state` and dealing
  starting hands) and `pages::apple-match.play` (the actual game: judge view vs.
  submitter view, anonymized reveal, winner pick). No `lobby`/pass-and-play page for
  v1, per section 1.
- Profile stats pages: `pages::profile.apple-match.{index,show}`.
- Homepage card (`resources/views/welcome.blade.php`), sidebar link
  (`resources/views/components/app-sidebar.blade.php`), `<x-player-stats>` wiring.
- Feature tests for deck/deal/round-resolution logic, attribution-never-leaks-into-a-
  public-property (mirroring Family Feud's `not->toHaveKey('answers')`-style
  assertion), turn/judge authorization, and stats; Browser tests for the real
  click-through per seat role (submitter's own view vs. the judge's own view, per this
  app's own established "one real browser page per test, other seats DB-seeded"
  convention — see `docs/DEVLOG.md`'s "Automated browser testing" section for why two
  simultaneous `visit()` pages in one test reliably hangs) and the online-room
  join/start flow.
- `docs/DEVLOG.md`/`docs/TODO.md`/`docs/CHANGELOG.md` updated per this repo's standing
  documentation convention.
