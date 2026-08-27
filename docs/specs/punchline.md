# Punchline — Build Spec

Status: **ready to build from.** Open design questions (below) were answered by the user on
2026-08-23.

**Naming:** "Quiplash" is Jackbox Games' trademark. Ships under the display name
**"Punchline"** — chosen directly by the user, before any code exists, so (unlike Boggle/
Yahtzee/Family Feud/Word Blocks, all of which were renamed after the fact — see
`docs/DEVLOG.md`'s "Game renaming" section) there's no internal-identifier-vs-display-name
split to maintain. Enum case, route names, DB values, and CSS classes can all just be
`Punchline`/`punchline`.

---

## 1. Core design decision (read first)

**Online rooms only, no pass-and-play — same reasoning as Best Match**
(`docs/specs/apple-match.md` §1). Every player must write their own answers without the
person they're matched against seeing them mid-write; a single shared device can't provide
that privacy the way per-device state can. A local/offline mode isn't ruled out forever, just
deferred the same way Best Match's own spec deferred it.

**The one genuinely new problem this game poses: concurrent, independently-progressing
matchups within a single round.** Every other room game in this app (Boggle rooms, Yahtzee,
Family Feud, Farkle, Best Match, Clear the Deck) has exactly one shared phase for the whole
table at a time. Punchline's rounds 1–2 run several 2-or-3-player matchups in parallel, each
moving through writing → voting → closed on its own clock — an early-finishing matchup can be
mid-vote while a slower one is still being written. This is the single biggest new piece of
room-game infrastructure since the room system itself, and the rest of this spec leans on
already-proven patterns (Best Match's secret-attribution-via-`Cache` trick, Farkle's
pure-function service style, Boggle's countdown-timer UI) everywhere they apply, and says so
explicitly where nothing existing does.

## 2. Design decisions

### Round structure — fixed 3-round game, no host-configurable round count

Point pools escalate per round: **Round 1 = 1000, Round 2 = 2000, Round 3 = 3000** (each
matchup gets that round's full pool as its own pot — the pool is not divided across a round's
several matchups).

**Rounds 1–2 ("pairing rounds").** For `P` seated players, generate that round's `P` prompts so
every matchup is a plain 2-player pair, and every player answers exactly 2 different prompts
against 2 different opponents (docs/concerns.md):
- **Even `P`** → the standard round-robin **circle method** run twice (fix seat `P-1`, rotate
  the rest; hand-verified for `P=4`: pass 0 → `{(3,0),(1,2)}`, pass 1 → `{(3,2),(0,1)}` — 4
  distinct pairs, no repeats, every seat exactly twice).
- **Odd `P`** → a single **Hamiltonian cycle** over all `P` seats: `(0,1), (1,2), ..., (P-1,0)`.
  `P` distinct pairs, every seat appearing in exactly 2 of them (its two cycle-neighbors), no
  pair repeated. For `P=3` this is exactly `(0,1), (1,2), (2,0)` — three head-to-head matchups,
  each answered by 2 players, each player voting on the one prompt they weren't in.
  (An earlier version paired odd `P` via a "core + shared triple" scheme so one prompt could be
  answered by 3 players at once; docs/concerns.md later replaced that requirement with "every
  prompt is a plain pair," which the cycle achieves directly and more simply.)

Once every author in a specific matchup submits, that matchup's answers become visible —
anonymized, no author name — to every other seated player, who vote for the funnier one.
Voting eligibility is one general rule: `eligibleVoters = allSeats - matchup.seatIndices`;
**if that set is empty** (only possible when a matchup already contains everyone — round 3 is
the only remaining case now that rounds 1–2 are always plain pairs), fall back to
`eligibleVoters = matchup.seatIndices` and disallow voting for your own answer.

**Round 3 ("final round").** One shared prompt for every player; each player writes **one**
answer to it (docs/concerns.md). Once everyone's submitted (or the writing timer expires),
voting opens on every submitted answer from every player at once. Every player gets **3
votes**, freely stackable on up to 3 different other players' answers (never their own).
Modeled as a single matchup whose `seatIndices` = every seat, `answersPerSeat` = 1, and
`voteLimit` = 3 — vote count is a field of its own now, not derived from `answersPerSeat`
(an earlier version required 3 answers per player specifically so `answersPerSeat === 3` could
double as "this matchup allows 3 votes"; decoupling the two was needed once round 3 dropped
back to 1 answer per player) — the exact same submit/reveal/vote/tally machinery as a
pairing-round matchup, not a separate game mode.

### Scoring — proportional pool split by vote share

`points = round50(pool * votesForThisAnswer / totalVotesInThisMatchup)`. If a matchup's total
vote count is 0 (everyone eligible timed out), split the pool evenly across its answers
instead. Rounding (`round50(x) = round(x / 50) * 50`) is **not** remainder-corrected back to an
exact pool total — a hand-traced 1/1/4-of-6 vote split leaves a real 50-point shortfall, which
is accepted as bounded, cosmetic drift rather than "fixed" with a largest-remainder pass
(consistent with `Farkle\Scorer` favoring the simplest correct thing over exactness).

**Sweep.** An answer that receives 100% of the votes cast in its matchup gets a flat bonus,
`round50(pool * 0.2)`, on top of its already-100%-of-pool score, plus a hook for a celebratory
UI reveal. The same definition (unanimous) applies at round 3's much larger scale (up to `3P`
total votes) rather than inventing a lower "landslide" tier for that round specifically — the
much higher bar at that scale is a feature (rarer, but the pool/bonus are correspondingly
bigger too), not a gap to patch.

### Timing — a real per-round countdown, not per-answer

No per-answer/per-matchup timer; one round-wide writing deadline and one round-wide voting
deadline, the same duration for every round. **Writing: 180s. Voting: 180s** — raised from an
initial 75s/150s writing (120s/240s split by round after real play found the original still too
tight), then to a flat 3 minutes for both writing and voting on every round (docs/concerns.md)
once players wanted more breathing room for voting too, not just writing. A player still
missing an answer when writing ends is auto-filled from a small
canned filler-answer list (deterministic per missing slot, so a partial round-3 timeout gets
distinct fillers, not the same line repeated); **no default score is awarded** to an absent
author — their opponent's real answer will typically just sweep.

This is a genuinely new pattern for the app: no existing room game synchronizes a countdown
across multiple devices (Boggle's own timer is single-shared-screen only). Generalizes
Boggle's `wire:poll`/`tick()` idea: `writingEndsAt`/`votingEndsAt` are plain epoch timestamps
in `GameSession.state`, so every browser renders its own local countdown without a
per-tick round trip. Every connected client polls `wire:poll.2s="checkTimeout"` — **not**
turn-gated, since any client should be able to trigger a deadline check — guarded by a
freshly-computed (never memoized) `now() >= deadline` check, wrapped in the same
`DB::transaction()` + `lockForUpdate()` pattern `syncOnlineState()` already uses elsewhere so
two simultaneous poll ticks can't double-apply an auto-fill.

### Player count — min 3, max 8 (enforced)

A deliberate deviation from every other room game's unenforced soft ceiling: the pairing
algorithm above is only defined and tested for `P ∈ [3, 8]`, so going higher needs new
combinatorics work first, not just a UI suggestion. Surfaced the same way Best Match surfaces
its own minimum-player check — a `$startError` string on the host's connection, not a 403
abort, since exceeding 8 is an ordinary occurrence, not a hostile bypass attempt.

### Prompt content — static curated bank, no deck/set concept

A flat, hand-written JSON prompt list (~150–200 prompts), seeded once — same shape as Best
Match's card seeding, minus the deck abstraction (nothing here asked for user-authored/AI-
generated prompt sets the way Best Match's cards were explicitly designed forward-compatible
for). No LLM-generated content: this app's only existing LLM use
(`FamilyFeud\JudgeService`) judges a guess against a fixed answer list rather than *authoring*
content, and `docs/TODO.md`'s Task 19 already deferred Connections/Strands specifically because
this app has no resolved precedent for AI content authoring — reusing the proven
static-bank approach here avoids reopening that open question for no strong reason (prompt
variety from ~150–200 hand-written entries is plenty for this game's actual play cadence).

### Moderation — none

Matches the app's one existing precedent exactly: Family Feud's free-text team names are
already broadcast to other players unfiltered (`trim()` and a length cap only). Punchline's
player-written answers get the same treatment; no new profanity/content-filtering
infrastructure is introduced.

## 3. Data model

- New `GameType::Punchline` case (`app/Enums/GameType.php`): `label() → 'Punchline'`,
  `roomRoute() → 'punchline.room'`, `playRoute() → 'punchline.play'`. No entry needed in
  `supportsRooms()`/`isChallengeable()`'s exclusion lists (both default correctly for a new
  case); `supportsSpectating()` also defaults `true` correctly (only Boggle is excluded).
- **No new `room_seats` columns.** Unlike Best Match's `hand`, nothing here needs durable
  per-seat DB storage: in-progress draft text is an ordinary Livewire property that's never
  broadcast (Livewire only broadcasts the explicit `RoomGameStateUpdated` payload, not a
  component's own properties, to other connections); submitted-but-unrevealed answer text and
  vote tallies live in `Cache` only, cleared once a matchup closes.
- New `punchline_prompts` table (`id`, `text`, timestamps), seeded via
  `database/seeders/data/punchline_prompts.json` + `PunchlinePromptSeeder`.
- `GameSession.state` shape:
  ```php
  [
      'round'         => 1,                              // 1 | 2 | 3
      'phase'         => PunchlinePhase::Writing->value,
      'pool'          => 1000,                            // this round's per-matchup pot
      'seats'         => [['seatIndex' => 0, 'name' => 'Alice'], ...],
      'scores'        => [0 => 0, 1 => 0, ...],           // cumulative, updated as matchups close
      'writingEndsAt' => 1735000000,                      // epoch seconds; null once phase leaves Writing
      'votingEndsAt'  => null,                             // epoch seconds; set once round-level Voting starts
      'usedPromptIds' => [12, 47, ...],
      'matchups' => [
          [
              'id'              => 'r1-0',                 // "r{round}-{sequence}"
              'promptId'        => 47,
              'promptText'      => 'The worst gift you could give a toddler',
              'seatIndices'     => [0, 3],                 // 2 (rounds 1-2) | all P (round 3)
              'answersPerSeat'  => 1,                       // always 1 -- one answer per player, every round
              'voteLimit'    => 1,                       // 1 for rounds 1-2, 3 for round 3
              'status'          => 'writing',               // PunchlineMatchupStatus
              'submittedCounts' => [0 => 0, 3 => 1],        // per-seat filed-answer count, NEVER text
              'votedSeats'      => [],                       // who's cast a vote already, NEVER content
              'answers'         => [],                       // [] until status != writing; then anonymized+shuffled
              // once populated: [['answerId' => '3-0', 'text' => '...'], ...] -- no seatIndex, ever
              'results'         => null,                     // populated only once status === closed
              // once populated: [
              //   'totalVotes' => 5,
              //   'pointsByAnswerId'  => ['0-0' => 350, '3-0' => 650],
              //   'authorsByAnswerId' => ['0-0' => 0, '3-0' => 3],  // the reveal moment
              //   'sweep' => false, 'sweepBonus' => 0, 'sweepAnswerId' => null,
              // ]
          ],
          // ... one entry per prompt this round
      ],
      'message' => null,
  ]
  ```
- **Public vs. `Cache`-only split** — the direct extension of Best Match's own secret-
  attribution rule (`docs/specs/apple-match.md` §3) to a per-matchup, not per-round, scope:

  | Data | Where | Why |
  |---|---|---|
  | Anonymized answer text, per-seat submitted/voted counts, `results` once closed | `state` (public, broadcast) | Safe once every author for that matchup has submitted / the matchup has closed — no seat mapping until the reveal moment. |
  | Answer text pre-completion + author↔answerId mapping while writing/voting | `Cache` only, `punchline:{sessionId}:round:{r}:matchup:{id}:submissions` | Written progressively as each author submits; read once at writing→voting to build the anonymized public list; the seat-mapping side stays cache-only until the matchup closes, at which point it's copied into `state.matchups[i].results.authorsByAnswerId`. |
  | Who voted for what | `Cache` only, `punchline:{sessionId}:round:{r}:matchup:{id}:votes` — **never revealed, not even at close** | Only the aggregated per-answer count is derived from it into `results.pointsByAnswerId`/`totalVotes`; individual ballots stay secret forever, a distinct lifecycle from authorship (which *is* deliberately revealed). Cleared via `Cache::forget()` once the matchup closes. |
  | In-progress (unsubmitted) draft text | An ordinary public Livewire property, not `Cache`, not `state` | Never leaves the single connection that owns it — same reasoning Best Match already relies on for `$myHand`. |

  Owned by a new `App\Services\Punchline\BallotService` (see §5) rather than raw `Cache::` calls
  scattered across the Livewire component, so this rule is enforced in one place. (Later moved
  to `App\Services\SubmitVote\BallotService` unchanged once Hoax became the second real
  consumer — see §4's own update and `docs/specs/hoax.md`.)
- `GameParticipant.stats`: `pointsByRound` (3-int array), `sweepsWon` — same shape/spirit as
  Best Match's `green_cards_won`/`rounds_judged`.
- `App\Services\Stats\PunchlineStatsService` (`gamesForUser()`/`statsFor()`/`bestMetrics()`),
  mirroring `AppleMatchStatsService` — Highest score, Most sweeps won.

## 4. Shared-abstraction scope (submit → vote games)

The user is also planning a future Fibbage-style submit-then-vote game and asked whether
Punchline, Best Match, and that future game should share a "submit → vote" skeleton now.
**Decided not to build a shared abstraction yet.** Below the room-lifecycle layer already
generic and shared by every room game, the three diverge more than they overlap: Best Match's
judge makes a single subjective pick (no vote tally at all), Punchline runs `N` concurrent
matchups with proportional vote-split scoring, and Fibbage's defining trick (a real answer
seeded among the lies, two-sided scoring for fooling people vs. finding the truth) fits neither
shape. The one genuine overlap — track who's submitted, reveal anonymized once complete, track
who's voted, tally votes — is real but has exactly one confirmed consumer today.

**Chosen approach: isolated but extraction-ready.** Punchline's version of that cycle is its
own self-contained `App\Services\Punchline\BallotService`, decoupled from Punchline-specific
pairing/scoring so it doesn't leak matchup/round concepts into its own API (see §5's exact
signature) — but it stays inside `app/Services/Punchline/`, not a new shared namespace, and
**Best Match is not touched by this work**. When Fibbage is actually designed, lifting a shared
class out of two real, working consumers is a mechanical move; designing it now against one
real game plus a guess at Fibbage's actual needs would be the premature-abstraction version of
this same decision.

**Update (Hoax build, `docs/specs/hoax.md`): this happened.** Hoax turned out to need exactly
the same submit → anonymize-reveal → vote → tally cycle (one shared "matchup" per round instead
of several concurrent ones, but the same primitive), so `BallotService` moved to
`App\Services\SubmitVote\BallotService` with **zero method or signature changes** — the
"mechanical move" predicted above, confirmed. Best Match still isn't touched: its single-judge
model never had a vote tally to begin with, so it has nothing this class would help with.

## 5. Services & Livewire architecture

Three pure/near-pure services in `app/Services/Punchline/`, matching Farkle's `Scorer`/
`DiceRoller` precedent rather than Best Match's fully-inline style — unlike judging-a-raw-pick,
these are real algorithms/cycles worth testing in isolation:

- **`PairingService::generateRoundMatchups(int $playerCount): array`** +
  `promptCountForRound(int $playerCount): int` — the circle-method/Hamiltonian-cycle
  construction from §2. Tested invariants: matchup count always equals `P`, every matchup has
  exactly 2 seats, every seat appears in exactly 2 matchups against 2 different opponents,
  `sum(count($seatIndices)) === 2P`, no pair of seats faces off twice in the same round.
- **`ScoringService::scoreMatchup(int $pool, array $votesByAnswerId, array $answerIds): array`**
  — the pool-split/rounding/zero-vote/sweep-bonus rules from §2, pure and with no knowledge of
  voter identity (the caller aggregates `Cache` votes into counts first).
- **`FillerAnswers`** — small canned-line list + `pick(int $index): string`, deterministic by
  index.
- **`BallotService`** (now `App\Services\SubmitVote\BallotService` — see §4's update) — owns
  the submit → anonymize-reveal → vote → tally cycle, keyed per-matchup (`gameSessionId`,
  `round`, `matchupId`). Signature kept generic on purpose (ids/
  seats/text only, no `Prompt`/`Matchup` types) so a future extraction only has to move the
  class, not redesign its API: `submit(string $ballotKey, int $seatIndex, string $answerId,
  string $text): void`, `isComplete(string $ballotKey, array $expectedSeats, int
  $answersPerSeat): bool`, `revealAnonymized(string $ballotKey): array` (shuffled `{answerId,
  text}`, called once right after `isComplete()` first turns true), `vote(string $ballotKey,
  int $voterSeatIndex, array $answerIds): void`, `tally(string $ballotKey): array` (`answerId
  => voteCount`), `authors(string $ballotKey): array` (`answerId => seatIndex`, read once at
  close), `clear(string $ballotKey): void`.

Two phase enums, matching `AppleMatchPhase`'s terseness:
```php
enum PunchlinePhase: string { case Writing = 'writing'; case Voting = 'voting'; case RoundReveal = 'round_reveal'; case GameEnd = 'game_end'; }
enum PunchlineMatchupStatus: string { case Writing = 'writing'; case Voting = 'voting'; case Closed = 'closed'; }
```
A matchup's own `status` can be ahead of the round-level `phase` — the round stays `Writing`
until *every* matchup finishes writing, but an individual matchup flips to `voting` (and voting
genuinely opens for it) the instant its own authors finish. This is the direct mechanism behind
"an early matchup can be voted on while another is still being written," not a special case.
`Writing → Voting` (round-level) and `Voting → RoundReveal` both fire from two places: the
action that might complete the last remaining matchup, and the corresponding timeout check —
funneled through one private `advanceRoundPhaseIfReady()` so both paths agree.

Two Livewire pages, mirroring Best Match's split:
- `pages::punchline.room` (`punchline.room`, `/punchline/room/{code}`) — simpler than Best
  Match's (no host-configurable settings, fixed 3-round game): just the `MIN_PLAYERS`/
  `MAX_PLAYERS` gate via `$startError`. `onRoomStarting()` builds round 1's matchups via
  `PairingService`, creates the `GameSession`, dispatches `RoomGameStarting`.
- `pages::punchline.play` (`punchline.play`, `/punchline`) — round orchestration:
  `hydrateFromState()`/`syncOnlineState()`/`onStateUpdated()` exactly like Best Match, plus
  `submitAnswer()`, `castVote()`, `checkTimeout()`, `advanceRound()` (round `< 3` → next
  round's matchups via `PairingService`, phase back to `Writing`; round `=== 3` → write
  `GameParticipant` rows, `GameSession.ended_at`, `Room.status = Finished`, phase `GameEnd`).
  Every mutating action: a freshly-computed (never memoized) `abort_unless()` guard, delegates
  the actual secret read/write to `BallotService` rather than raw `Cache::` calls, ends with
  `syncOnlineState()`. Renders each in-progress matchup as its own card, branching on
  writing/voting/closed and this viewer's own role (author / eligible voter / spectator).
  `<livewire:room-new-game-notice>` and `<livewire:game-lobby>` reused unmodified.
- `routes/web.php`: `punchline.play` (bare) + `punchline/room/{code}` (`punchline.room`), no
  auth middleware — `mount()` handles guest provisioning itself, matching Best Match's entries.
  No `routes/channels.php` change needed (generic `room.{code}` presence channel).

## 6. Accessibility

Follows the same low-stim design system as every other game (see `docs/DEVLOG.md`'s "Site-wide
design system & chrome" section) — muted palette, no flashing/bouncing reveal animation, no
autoplay sound, respects `prefers-reduced-motion`. The sweep-bonus "celebratory reveal" hook
(§2) must follow this too — a color/shape/text change, not a flash or sound, same as every
other emphasis moment in this app. The new countdown timer UI (§2) is the one genuinely new
accessibility surface here: text-based remaining time, not just a shrinking bar, and no
flashing/color-pulse as the deadline approaches.

## 7. Deliverables for v1

- `App\Enums\GameType::Punchline` case + `label()`/`roomRoute()`/`playRoute()` entries.
  `App\Enums\PunchlinePhase`, `App\Enums\PunchlineMatchupStatus`.
- `punchline_prompts` migration + `PunchlinePrompt` model, seeded via `PunchlinePromptSeeder`
  from a new `database/seeders/data/punchline_prompts.json` (~150–200 prompts).
- `App\Services\Punchline\{PairingService,ScoringService,FillerAnswers}` +
  `App\Services\SubmitVote\BallotService` (moved there once Hoax needed it too — see §4).
- Livewire pages: `pages::punchline.room`, `pages::punchline.play`.
- `App\Services\Stats\PunchlineStatsService` + `pages::profile.punchline.{index,show}`.
- Homepage card (`resources/views/welcome.blade.php`), sidebar link
  (`resources/views/components/app-sidebar.blade.php`), `<x-player-stats>` wiring.
- Unit tests: `PairingServiceTest`, `ScoringServiceTest`, `BallotServiceTest`,
  `PunchlineStatsServiceTest`.
- Feature tests: room join/start + player-count gating; submit/vote authorization (no double
  submit, no self-matchup voting except the documented fallback, no self-answer voting, no
  double voting); a full round resolving end-to-end (scoring applied, `scores` updated, next
  round's matchups generated); writing-timeout auto-fill and force-advance; secret-attribution
  never leaking into a public property/`state` (mirroring Best Match's own
  `not->toHaveKey(...)`-style assertion) for both the submissions and votes `Cache` keys;
  `GameParticipant` rows/stats written correctly at game end.
- Browser tests: the concurrent multi-matchup UI actually renders correct per-matchup status
  (one real browser page per test, other seats DB-seeded, per this app's established
  two-simultaneous-`visit()`-pages-hangs convention — see `docs/DEVLOG.md`'s "Automated browser
  testing" section), the countdown UI, and the online-room join/start flow.
- `docs/DEVLOG.md`/`docs/CHANGELOG.md` updated per this repo's standing documentation
  convention.
