# Hoax — Build Spec

Status: **ready to build from.** Open design questions were answered by the user directly
(round count, scoring model) on 2026-08-27; everything else follows from those two decisions
plus established patterns from Punchline and Best Match.

**Naming:** "Fibbage" is Jackbox Games' trademark. Ships under the display name **"Hoax"** —
chosen directly by the user, before any code existed, so (matching Punchline's own precedent,
not Boggle/Yahtzee/Family Feud/Word Blocks' rename-after-the-fact history) there's no
internal-identifier-vs-display-name split to maintain. Enum case, route names, DB table/model,
and CSS ids can all just be `Hoax`/`hoax`.

---

## 1. Core design decision (read first)

**Online rooms only, no pass-and-play — same reasoning as Punchline and Best Match.** Every
player must write their own fake answer without anyone else seeing it mid-write; a single
shared device can't provide that privacy.

**The one genuinely new problem this game poses: a real, correct answer has to be seeded among
player-submitted lies, indistinguishable until reveal, with two-sided scoring for both writing a
convincing lie and finding the truth.** This doesn't fit either existing submit-then-vote
game's shape (`docs/specs/punchline.md` §4 anticipated this exact moment): Best Match's judge
makes a single subjective pick with no vote tally at all; Punchline runs several concurrent
matchups with a single proportional vote-share score per matchup. Hoax has neither — one shared
prompt per round like Punchline's round 3, but every vote is a single binary "did you fool this
person, or did they find you out" event, scored per-player rather than per-matchup. Structurally
this makes Hoax *simpler* than Punchline (no pairing, no per-round-matchup array — every round
has exactly one shared question for the whole table), even though its scoring is a genuinely new
shape.

## 2. Design decisions

### Round structure — fixed 5-round game, no host-configurable round count

Chosen by the user over a 3-round option (matching Punchline's count) specifically because each
Hoax round's cycle (one question, one answer each, one vote each) is faster than a Punchline
round, so more rounds fit the same rough playtime. No escalating point pool by round (see
Scoring below) — every round uses the identical point values, so there's nothing that needs to
scale with round number the way Punchline's pool does.

### Scoring — classic flat Fibbage formula, the same every round

The user chose this over a Punchline-style escalating pool specifically for simplicity: a
two-sided score (reward the liar *and* the truth-finder) doesn't cleanly decompose into a single
pool split the way Punchline's single-sided vote-share does, so a flat, well-understood formula
was preferred over inventing a new pool-splitting scheme for two different roles at once.

- **`TRUTH_POINTS = 1000`** — awarded to every voter who picks the real answer.
- **`FOOL_POINTS = 500`** — awarded to a lie's author, once for every *other* player who picks
  it (stacks: fool 2 people, earn 1000).
- No sweep bonus, no proportional split, no penalty for guessing wrong beyond simply not scoring
  that round. A single seat's total for a round can combine both roles at once (they wrote a
  lie that fooled people *and* separately voted for the truth themselves) — this is expected,
  not a bug, since every seat is always both an author and a voter every round.

`App\Services\Hoax\ScoringService::scoreRound()` is pure and takes per-voter choices (not just
an aggregated tally, unlike Punchline's `ScoringService`) — see §4 for why that needed a small,
deliberate addition to the shared `BallotService`.

### The real answer's "author" — a sentinel seat index, not a special case

The real answer is submitted into the same ballot as every player's fake answer, "authored" by
`ScoringService::TRUTH_SEAT_INDEX = -1` — a value that can never collide with a real room seat
(seats are always `>= 0`). `BallotService::submit()`/`revealAnonymized()` need zero changes to
support this: `revealAnonymized()` already strips all seat info from what it returns, so the
real answer is naturally camouflaged among the lies the instant it's submitted, and
`authors()` reveals the `-1` sentinel at round-close exactly like it reveals any other seat.

### Timing — shorter than Punchline's, per-round the same both ways

**Writing: 90s. Voting: 60s.** Shorter than Punchline's flat 180s/180s since writing one
blank-fill is quicker than a freeform joke, and voting has fewer options to read (`playerCount +
1`, vs. Punchline's own tally per matchup). A player still missing an answer when writing ends
is auto-filled from `App\Services\Hoax\FillerAnswers` (own canned lines, e.g. "something," "I
have no idea") — no points are ever awarded for one of these.

### Player count — min 3, max 8 (enforced)

Same bounds as Punchline, for the same reason: enough players for the deception to actually be
interesting (with 2 players there's only ever one lie total to weigh against the truth). A
`$startError` string on the host's connection, not a 403, matching every other room game's
soft-ceiling convention.

### Question content — static curated bank, no deck/set concept

Same "no deck/set concept" default as Punchline's prompts and Best Match's cards — a flat,
hand-written bank (`database/seeders/data/hoax_questions.json`, 83 entries), each a
fill-in-the-blank trivia statement (a literal `____` placeholder) plus its real answer.
Deliberately smaller than Punchline's ~150 prompts: trivia has a correctness bar comedic prompts
don't (a wrong "funniest answer" opinion is harmless; a wrong "fact" is a real content bug), so a
smaller, carefully fact-checked bank was preferred over a larger one with a higher error risk.
No LLM-authored-content problem beyond the same one Punchline already accepted (this app's
existing content banks are all hand-authored, not generated per-request) — see
`docs/specs/punchline.md` §2's own reasoning for why that's the established low-risk default
here. Same `usedQuestionIds`-on-`GameSession.state` no-repeat mechanism as Punchline's
`usedPromptIds`, falling back to reuse once the bank is exhausted.

**Audited and fully replaced once, post-launch (2026-08-27).** The original 84-question bank
had three real problems, found by actually reading through it rather than trusting that
"correct and obscure-sounding" was enough: (1) a chunk were viral-tier common knowledge (octopus
hearts, Scotland's unicorn, "a murder of crows") that most tables would already know, defeating
the bluffing mechanic entirely; (2) several had the blank positioned somewhere the sentence's own
grammar or an already-well-known base fact gave away, e.g. "...hotter than the surface of the
____" only ever admits "the Sun," or "the unofficial national dish of ____ is poutine" is
trivially inferable by anyone who's heard of poutine; (3) a broader "moderately known" middle
tier that wasn't viral but wasn't genuinely obscure either. The replacement bank was authored
fact by fact against real sources (`WebSearch`, not assumed-from-memory) specifically for the
riskier/most specific claims — numbers, "first X" claims, court case names — rather than
trusting recalled trivia wholesale, and every surviving/reworked/new entry was checked against
the same "does the blank position force or trivially imply the answer" question before being
included.

### Answer matching — rejected and asked to retry, at submission time

Raised as a real, previously-unhandled gap: nothing stopped a player from typing the actual real
answer as their own "fake" submission (by accident, or as a way to fish for credit), which would
have put two identically-worded entries in the reveal — one under the real `truth` sentinel, one
under that player's own seat — with no detection at all. A voter who then picked the player's
copy would be scored as *fooled* (crediting that player with `FOOL_POINTS`), not as having found
the truth, silently corrupting both the round's scoring and its comedic payoff.

- **`App\Services\Hoax\AnswerMatcher::matches(string $submitted, string $answer, array
  $alternateAnswers = []): bool`** — pure, stateless, checked inside `submitAnswer()` before the
  ballot ever accepts the text. Normalizes both sides (lowercase, trim, collapse whitespace,
  strip trailing punctuation and thousands-separator commas, drop one leading "a"/"an"/"the")
  and compares for an **exact** match against the real answer or any of its
  `hoax_questions.alternate_answers`. Deliberately not fuzzy/typo-tolerant (no Levenshtein-style
  "close enough") — that risks false-positive rejections of a genuinely different fake answer
  that merely looks similar, which is worse than the rare true collision this exists to catch.
- **`hoax_questions.alternate_answers`**: a new nullable JSON column (added via its own migration
  after the initial `hoax_questions` table, matching this app's established "bolt a nullable
  column on when a new game needs a bit more per-row data" convention rather than a new table).
  A short, hand-authored list of accepted equivalent phrasings per question — synonyms ("hick" /
  "rube" / "bumpkin" / "country bumpkin"), digit-vs-word numbers ("forty" / "40"), or spelling
  variants — authored alongside the question itself, not inferred automatically. An LLM-judge
  approach (this app already has one precedent, `FamilyFeud\JudgeService`) was considered and
  rejected for this specific check: catching "basically typed the real fact" is a much narrower
  problem than Family Feud's genuinely open-ended survey-response judging, and a deterministic
  list is cheaper, faster, and doesn't risk an unpredictable judgment call on every submission.
- **A match rejects via `$submitError` (a plain public string, read by the writing-form UI), not
  `abort_unless()`.** A hard abort here would trip this app's global Livewire error handler into
  a silent redirect to the homepage on anything other than a deliberate 422 — exactly the class
  of bug this app has been burned by before (see Punchline's own staleness-handling history) —
  and "typed the real fact by accident" is an ordinary, non-malicious mistake that should never
  risk that. `$draftAnswer` is left untouched on rejection so the player can edit what they
  already typed rather than starting over, and `submitAnswer()` clears any previous
  `$submitError` on every fresh attempt.
- **Display-time uppercasing removes a second, quieter tell.** Independent of the matching
  question above: the real answer's casing always comes from the curated seed data (clean,
  consistent style — e.g. "Nebraska," "the Sun"), while a player's fake answer is typed however
  they happen to type it (all-lowercase from a phone keyboard, careless capitalization, etc.).
  Left alone, that consistent-vs-inconsistent formatting difference would itself become a
  cross-round tell, letting an attentive table learn "the cleanly capitalized one is always real"
  independent of the actual trivia. `interpolatedText()` now uppercases every candidate answer
  the same way before display, purely a rendering change — nothing about storage or matching
  changed.

### Moderation — none

Same as Punchline — no profanity filter on player-submitted text, matching this app's existing
stance for private-room social games.

## 3. Data model

`GameSession.state` is flat (no per-matchup array — every round has exactly one shared
question, unlike Punchline's rounds 1–2):

```php
[
    'round'          => 1,                              // 1..5
    'phase'          => HoaxPhase::Writing->value,
    'questionId'     => 12,
    'questionText'   => 'In 1962, a farmer in ____ discovered a mummified ____.',
    'seats'          => [['seatIndex' => 0, 'name' => 'Alice'], ...],
    'scores'         => [0 => 0, ...],                  // cumulative
    'pointsByRound'  => [],                             // seatIndex => [round => points]
    'truthsFound'    => [],                             // seatIndex => count of rounds correctly guessed
    'writingEndsAt'  => 1735000000,                     // epoch seconds; null once phase leaves Writing
    'votingEndsAt'   => null,                           // epoch seconds; set once Voting starts
    'usedQuestionIds' => [12, ...],
    'submittedSeats' => [],                             // list<int>, this round
    'votedSeats'     => [],                             // list<int>, this round
    'answers'        => [],                             // [] until voting opens; {answerId, text} anonymized, incl. the truth
    'results'        => null,                           // populated only once phase === RoundReveal
    'message'        => null,
]
```

`results` once populated:

```php
[
    'pointsBySeat'       => [0 => 1500, 2 => 500],       // this round's earnings only
    'truthAnswerId'      => 'truth',
    'authorsByAnswerId'  => ['0-0' => 0, '1-0' => 1, 'truth' => -1],  // -1 = the sentinel
    'votesByAnswerId'    => ['0-0' => 2, 'truth' => 1],
    'seatsWhoFoundTruth' => [2],
]
```

Ballot key: `"hoax:{sessionId}:round:{r}"` — one ballot per round, not per-matchup (there's only
ever one prompt).

`hoax_questions` columns: `id`, `text` (the `____`-blanked statement), `answer`, and
`alternate_answers` (nullable JSON list of accepted equivalent phrasings, added in its own
follow-up migration — see the "Answer matching" subsection of §2).

- `GameParticipant.stats`: `pointsByRound` (5-int array), `truthsFound` — same shape/spirit as
  Punchline's own `pointsByRound`/`sweepsWon`.
- `App\Services\Stats\HoaxStatsService` (`gamesForUser()`/`statsFor()`/`bestMetrics()`), mirroring
  `PunchlineStatsService` — Highest score, Most truths found.

## 4. Shared-abstraction scope: `BallotService` extraction (predicted, now real)

`docs/specs/punchline.md` §4 deliberately kept `BallotService` inside
`App\Services\Punchline`, decoupled from Punchline-specific concepts in its own API, specifically
so it could be extracted once there was a second real consumer to design the shared shape
against. Hoax is that second consumer. **The extraction happened exactly as predicted**:
`BallotService` moved to `App\Services\SubmitVote\BallotService` with **zero method or
signature changes** — Hoax's own submit → anonymize-reveal → vote → tally cycle needed nothing
the class didn't already do generically. Best Match is untouched: its single-judge model never
had a vote tally to begin with.

**One real, narrow addition was still needed**: a new `voterChoices(string $ballotKey): array`
method, returning each voter's own choice(s) (`voterSeatIndex => list<answerId>`) rather than
just `tally()`'s aggregated counts. This is a second, deliberate exception to "who voted for
what stays secret forever," alongside the existing `authors()` — and it's specific to Hoax, not
a Punchline need retrofitted awkwardly: Punchline's scoring only ever needs `tally()`'s
aggregated counts, so per-voter identity really does stay secret forever for it, but Hoax's
entire reveal moment is built around exposing exactly this ("you fooled Alice and Bob!", "Priya
found the real answer!") — not optional for that consumer the way it is for Punchline. Both
methods' own docblocks explain this rather than silently changing what "secret" means for every
consumer.

## 5. Services & Livewire architecture

Three services in `app/Services/Hoax/`, plus the shared `App\Services\SubmitVote\BallotService`:

- **`ScoringService::scoreRound(array $voteByVoterSeat, array $authorsByAnswerId, string
  $truthAnswerId): array`** — the flat truth/fool-points math from §2, pure, taking per-voter
  choices (not just an aggregated tally, per §4). Returns `{pointsBySeat, seatsWhoFoundTruth,
  votesByAnswerId}`. `TRUTH_POINTS`/`FOOL_POINTS`/`TRUTH_SEAT_INDEX` constants live here.
- **`FillerAnswers`** — small canned-line list + `pick(int $index): string`, deterministic by
  index, mirroring `Punchline\FillerAnswers` exactly.
- **`AnswerMatcher::matches(string $submitted, string $answer, array $alternateAnswers = []):
  bool`** — the exact-after-normalization real-answer-collision check from §2's "Answer
  matching" subsection, checked inside `submitAnswer()` before a draft is ever accepted.

One phase enum, matching `PunchlinePhase`'s shape but with no matchup-status counterpart (there's
no matchup concept at all):

```php
enum HoaxPhase: string { case Writing = 'writing'; case Voting = 'voting'; case RoundReveal = 'round_reveal'; case GameEnd = 'game_end'; }
```

Two Livewire pages, mirroring Punchline's split:

- `pages::hoax.room` (`hoax.room`, `/hoax/room/{code}`) — simpler than Punchline's (no
  `PairingService`, no matchup-count validation beyond the flat player-count gate). `MIN_PLAYERS`/
  `MAX_PLAYERS` gate via `$startError`. `onRoomStarting()` builds round 1's shared question,
  creates the `GameSession`, submits the real answer into round 1's ballot under the sentinel
  seat, dispatches `RoomGameStarting`.
- `pages::hoax.play` (`hoax.play`, `/hoax`) — round orchestration:
  `hydrateFromState()`/`syncOnlineState()`/`onStateUpdated()` exactly like Punchline's, plus
  `submitAnswer()` (one slot, not Punchline's per-slot draft keying — there's only ever one
  question active at a time), `castVote(string $answerId)` (a single answer, not an array —
  no stacking, unlike Punchline's round 3), `checkTimeout()`, `advanceRound()` (round `< 5` →
  next round's question, phase back to `Writing`; round `=== 5` → write `GameParticipant` rows,
  `GameSession.ended_at`, `Room.status = Finished`, phase `GameEnd`). Every seated player is
  always both an author and a voter every round (no Punchline-style author/voter split), so
  `votableAnswers()` only ever needs to exclude the viewer's own answer, never a whole matchup's
  worth of authors. `interpolatedText(string $answerText): string` substitutes `$answerText`
  into `$questionText`'s `____` placeholder (both HTML-escaped first) for the classic
  fill-in-the-blank reveal — a real answer's full completed sentence, not just its bare text.
  `<livewire:room-new-game-notice>` and `<livewire:game-lobby>` reused unmodified.
- `routes/web.php`: `hoax.play` (bare) + `hoax/room/{code}` (`hoax.room`), no auth
  middleware — `mount()` handles guest provisioning itself, matching Punchline's entries.

## 6. Accessibility

Same low-stim design system as every other game (see `docs/DEVLOG.md`'s "Site-wide design
system & chrome" section) — muted palette, no flashing/bouncing reveal animation, no autoplay
sound, respects `prefers-reduced-motion`. The countdown timer UI is text-based remaining time,
same as Punchline's own, not just a shrinking bar, and no flashing/color-pulse as the deadline
approaches. The reveal's real-answer highlight is a border-color change, not a flash.

## 7. Deliverables for v1

- `App\Enums\GameType::Hoax` case + `label()`/`roomRoute()`/`playRoute()` entries.
  `App\Enums\HoaxPhase`.
- `hoax_questions` migration (plus a follow-up migration adding `alternate_answers`) +
  `HoaxQuestion` model, seeded via `HoaxQuestionSeeder` from a new
  `database/seeders/data/hoax_questions.json` (83 questions, fact-checked and audited for
  obscurity — see §2). The JSON is the authoritative source for the table's contents:
  `HoaxQuestionSeeder` both upserts every row it lists and prunes any existing row whose text
  no longer appears in it, so re-seeding after editing the bank converges every environment to
  exactly the current file rather than leaving dropped questions behind.
- `App\Services\Hoax\{ScoringService,FillerAnswers,AnswerMatcher}` + the shared
  `App\Services\SubmitVote\BallotService` (moved from `App\Services\Punchline`, plus its new
  `voterChoices()` method — see §4).
- Livewire pages: `pages::hoax.room`, `pages::hoax.play`.
- `App\Services\Stats\HoaxStatsService` + `pages::profile.hoax.{index,show}`.
- Homepage card (`resources/views/welcome.blade.php`), sidebar link
  (`resources/views/components/app-sidebar.blade.php`), `<x-player-stats>` wiring, plus a
  drive-by fix for a pre-existing gap found while wiring this in
  (`pages::profile.games`'s `showRoute()` match was missing a `GameType::Punchline` arm
  entirely).
- Unit tests: `ScoringServiceTest`, `FillerAnswersTest`, `AnswerMatcherTest` (all
  `tests/Unit/Hoax/`), plus new `BallotServiceTest` coverage for `voterChoices()` (moved to
  `tests/Unit/SubmitVote/`).
- Feature tests (`tests/Feature/Games/HoaxRoomTest.php`): room join/start + player-count/bank
  gating; a full round resolving end-to-end with correct truth/fool-point math; self-vote
  exclusion (own answer never offered + server-side drop); the real-answer submission rejected
  and retried (exact match, a known alternate spelling, and a successful retry afterward);
  writing/voting-timeout auto-fill and force-close; secret ballot state never leaking into a
  public property/`state`; a full 5-round game writing correct `GameParticipant` rows/stats;
  host-only `advanceRound`/`endGameManually`; stale-connection re-sync races (mirroring
  Punchline's own coverage of the same class of bug).
- Browser tests (`tests/Browser/HoaxRoomBrowserTest.php`): the interpolated-sentence voting list
  actually renders (a plain `Livewire::test()` can't see this), own-answer exclusion, the
  mobile answer-input font-size fix applied from the start (not after a bug report, unlike
  Punchline's), host-only Next Round/End Game button visibility.
