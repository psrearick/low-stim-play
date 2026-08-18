# Clear the Deck — Build Spec

Rules as originally provided are kept verbatim in the [Appendix](#appendix-original-rules)
below. See `docs/TODO.md` #1.

---

## 1. Core design decision (read first)

This is a turn-based shedding/climbing card game for 3–10 players built around a
shared, always-visible center pile — closer in shape to a game like Nertz or nine-card
golf than to anything currently in this app. It has **two different kinds of hidden
information**, which is the main thing that makes it harder than this app's existing
games:

- Each player has a **private hand** (identities hidden from other players, but the
  *count* must stay visible to everyone — see rule under "Play" below).
- Each player also has **face-down table cards that are hidden even from that
  player themselves** until the moment they choose to play one blind. Nothing else in
  this app has that "secret from its own owner" property — Family Feud's hidden answer
  key is secret from *all* players but known to the server/judge; this needs a card
  whose identity is decided (dealt) up front but withheld from every client, including
  its owner's, until flip.

This means: no single-shared-screen model (real hidden info exists, unlike Family
Feud), and no simple "reveal the whole hand at once" model either (unlike a simpler
private-hand game) — the per-seat view needs three tiers of visibility: fully public
(center pile, face-up table cards, hand *counts*), private-to-owner (hand card
identities), and private-to-nobody-yet (face-down table cards, pre-dealt but withheld
from everyone until played).

Needs online rooms with a private per-seat view — the room-decoupling infrastructure
this depends on is already built (see `docs/DEVLOG.md`'s "Room system" section). Best
Match (`docs/TODO.md` #1, née "Apples to Apples," see
[`docs/specs/apple-match.md`](apple-match.md)) got fully specced first and already
establishes the "hidden per-seat state" pattern to reuse here: never assign secret
state to a rendered Livewire public property outside the one action that needs it,
resolving it fresh from `GameSession.state`/the DB instead. This game's face-down,
secret-from-its-own-owner cards are a harder version of the same problem, not a
different one.

## 2. Physical rules → digital equivalents

The rules' deck-count-scales-with-player-count and one-card-at-a-time deal order (see
"Preparation" in the appendix below) exist only to make a physical shuffle deal fairly.
A digital version doesn't need to replicate "how many 52-card decks": one virtual deck
of `20 × player count` cards (standard 13 values, repeating as many times as needed),
shuffled server-side, is equivalent. No open question here — just don't carry the
physical constraint into the data model.

## 3. Resolved design decisions

- **Player count range: 3–10**, enforced by the room page the same way Best Match
  enforces its own 3-player minimum (`docs/specs/apple-match.md`) — a host-visible error
  instead of a silently-inert Start Game button, both below 3 and above 10.
- **Round count.** Host-configurable fixed round count at room setup, min 1 / max 10 /
  default 1 (a stepper control, same shape as Best Match's target-score setting) —
  lowest cumulative score across all rounds wins the game.
- **Face-down card identity storage.** Dealt once at deal time and stored server-side in
  a per-seat column, never touching any Livewire public property (own or broadcast)
  until the instant it's actually played — see "Face-down card identity" under Data
  model below for the concrete mechanism.
- **Set eligibility.** A "Clear the Deck" set must include at least one card newly
  played this turn (not something with no player action behind it at all), but that
  newly-played contribution can come from *any combination* of the current player's own
  hand, face-up cards, and face-down cards, with no minimum required from hand — a play
  made entirely from a player's own face-up and/or newly-flipped face-down cards, using
  nothing from their hand, is legal, including one that completes (or entirely forms) a
  set by pure luck. Cards are never auto-selected; the player always chooses exactly
  which of their own cards to attempt to play.
- **Wild Ten: always clears.** Every Ten played (alone or with other Tens) clears the
  deck immediately, bypassing the equal-or-lesser-value comparison entirely — there's no
  "play a Ten as an ordinary low card" path.
- **AFK / turn-timer handling: none for v1.** No other online-room game in this app has
  one; this follows that existing precedent rather than introducing a new pattern.
- **Name/trademark check: keep "Clear the Deck."** It's a generic description of the
  mechanic, not a specific commercial product's name — same reasoning the game already
  used to self-assess as lower risk than Boggle/Yahtzee/Family Feud/Apples-to-Apples
  ever were.

One further implementation-level detail, decided independently (not re-confirmed with
the user — narrow enough to be a judgment call under section 2's "pick the simplest
faithful digital equivalent" guidance rather than a design decision worth a second round
of questions):

- **A face-down flip is validated as part of the whole attempted play**, whatever else
  is selected alongside it (hand cards, face-up cards, or nothing else at all): the
  server resolves the selected face-down cards' real values and checks the *entire*
  combined selection matches. If it doesn't, the whole play is rejected with **no state
  change and nothing revealed to the client** — the same as if the flip never happened
  — keeping the "you can legally attempt, and fail, a play using only unknown face-down
  cards" gamble intact.

  **Revised after real play** (superseding an earlier version of this spec, which had a
  fully blind/atomic flow with no reveal step at all — see `docs/DEVLOG.md`'s Clear the
  Deck section for why): once a selection *does* turn out valid and includes a
  not-yet-revealed face-down flip, the game doesn't commit it immediately. It reveals
  the value to the acting player only and pauses — via `$pendingFlipValue` on
  `pages::clear-the-deck.play` — offering to add any other still-unselected hand/face-up
  cards of that same value, with non-matching cards visibly disabled rather than merely
  undocumented; a "Confirm" button (replacing "Submit" for this one step) commits
  whatever's selected at that point. This only triggers when there's actually another
  matching card available to offer — a flip with nothing else to add still resolves in
  one step, same as a play with no face-down cards in it at all. This matches the
  physical rule's own literal sequencing ("once revealed, that card must be played...
  additional matching cards... can be played along with it") more closely than the
  original all-at-once design did.
- **Ten's 25-point penalty applies wherever it remains at round end** (hand, face-up, or
  face-down), not hand-only. The rules' own Wild section only calls out "any Tens held
  in a player's hand," but the Scoring section's actual scoring rule is phrased as "the
  values of the cards remaining in their hand (including those on the table, both face
  up and face down)" immediately followed by "Tens held at the end are 25 points" with
  no hand-only qualifier — treated as the operative, more general statement.

## 4. Data model

- `GameType::ClearTheDeck` (`clear_the_deck`) — kept alongside the existing cases in
  `App\Enums\GameType`, same `label()`/`roomRoute()`/`playRoute()` shape as every other
  case.
- `App\Enums\ClearTheDeckPhase`: `Playing`, `RoundEnd`, `GameEnd` — simpler than Best
  Match's `AppleMatchPhase` since this is a genuine single-active-player turn loop, not
  a simultaneous-submission phase.
- **Cards are plain integers 1–13** (Ace low = 1 … King high = 13) — "Suits are not used
  in this game" (appendix) means there's no card-content table to seed or model the way
  Best Match's `apple_match_cards` needed; a virtual deck for a room of *n* seats is
  `ceil(20n / 13)` full 1–13 sets, shuffled, first `20n` taken (`docs/specs/apple-match.
  md`-style "don't carry the physical shuffle-deal into the data model" reasoning from
  section 2 above, applied the same way here).
- **`room_seats` gets two new nullable JSON columns**, alongside the existing generic
  `hand` column (reused here for the private 12-card hand, same as Best Match reused it
  for card ids — this game just stores plain integers in it instead):
  - `table_face_up` — the seat's remaining face-up table cards, an unordered bag of
    integers (starts as 4 dealt values, shrinks as played). Public: every seat's own
    `table_face_up` is broadcast in `$state`, since face-up table cards are visible to
    everyone in the physical game.
  - `table_face_down` — the seat's remaining face-down table cards, an unordered bag of
    integers (starts as 4 dealt values, shrinks as played or flipped-and-rejected...
    no — shrinks *only* on a successful flip-and-play, never on a rejected attempt, see
    section 3). **Never broadcast, never loaded into any Livewire public property** —
    read fresh from the database only inside the one action (`playCards()`) that needs
    to resolve a flip, the same "resolve secret state fresh from the DB, never assign it
    to a rendered property" rule Best Match's round-attribution cache established
    (`docs/DEVLOG.md`'s Best Match section) and this spec's own section 1 flags as the
    harder version of that same problem.
- **Face-up/face-down as unordered bags, not per-slot pairs.** The physical dealing
  description (4 face-down, then 4 face-up "on top of each down card") implies a
  1:1 slot pairing that determines exactly which face-down card a given face-up card
  covers. Since neither a face-up card's identity nor a face-down card's identity is
  tied to anything else once dealt (there's no gameplay difference between "slot 1's
  face-down" and "slot 3's"), the covering rule reduces to a pure count: the number of
  currently-flippable (uncovered) face-down cards is `max(0, count(table_face_down) -
  count(table_face_up))`. This is mathematically equivalent to strict per-slot covering
  as long as specific pairing never matters — it doesn't here — and avoids ever storing
  or reasoning about slot indices at all.
- **`GameSession.state`** (broadcast, public): `roundTarget`, `currentRound`, `seats`
  (`{seatIndex, name, handCount, tableFaceUp, tableFaceDownCount}` — note `handCount`/
  `tableFaceDownCount` are counts only, never contents, matching "hand size must remain
  visible" from the appendix without leaking hand identity), `scores` (cumulative,
  lower-is-better), `dealerSeatIndex`, `turnSeatIndex`, `centerStack` (`list<int>`, full
  contents — needed since picking up "the entire center stack" requires more than just
  its top value), `phase`, `lastMessage` (human-readable description of the most recent
  action, e.g. "Alice cleared the deck with four 7s!"), and (RoundEnd only)
  `roundScores` (this round's per-seat point total, cleared on `nextRound()`).
- **Turn resolution after a valid same-value play of card value `V`** (see section 3
  for how a play's cards are chosen/validated). Rule changed 2026-08-18
  (`docs/TODO.md`'s former #21): a forced pickup (case 3) now ends the turn immediately
  instead of granting an extra one — the original design below is kept as a record of
  what shipped first, not as the current behavior for that case.
  1. `V === 10`: deck clears unconditionally (extra turn, same player).
  2. Otherwise, `runLength` = however many trailing `centerStack` entries already equal
     `V`, plus however many cards were just played. If `runLength >= 4`: deck clears
     (extra turn, same player).
  3. Otherwise, if `V` is greater than `centerStack`'s current top value (non-empty
     stack only): the entire existing `centerStack`, plus the cards just played, all
     move into the acting seat's own hand (persisted to `RoomSeat.hand`); turn passes to
     the next seat, same as case 4 below (not an extra turn, as originally shipped).
  4. Otherwise (a legal equal-or-lesser play that didn't complete a set): the played
     cards join `centerStack`; turn passes to the next seat.
  - "Going out" (hand and both table bags all empty) is checked after any of the above
    — in practice it can only actually happen after case 4, since a pickup (case 3)
    always adds cards to the acting seat's hand, and cases 1–2 also keep cards with
    them.
- **Round end**: triggered the instant any seat goes out. Every other seat's remaining
  cards (hand + `table_face_up` + `table_face_down`, revealing face-down values now that
  the round is over) are scored (Ace=1, 2–9 face value, Jack=11, Queen=12, King=13,
  Ten=25 wherever it remains — see section 3); the seat that went out scores 0. Added to
  each seat's cumulative `scores`. If `currentRound >= roundTarget`: `GameEnd`, one
  `GameParticipant` per seat (`score` = final cumulative total, `result` = `Win` for the
  sole lowest total, `Draw` for a tied lowest, `Loss` otherwise — ties are possible here,
  unlike this app's other "first to N" games, since this is cumulative scoring across a
  fixed round count). Otherwise: `RoundEnd`, any seated player can trigger `nextRound()`
  (shared "ready to continue" action, same convention as Best Match's own `nextRound()`)
  which rotates the dealer, re-deals a fresh 20 cards/seat, resets `centerStack`, and
  advances `currentRound`.
- `App\Services\Stats\ClearTheDeckStatsService` follows the `statsFor()`/
  `gamesForUser()`/`bestMetrics()` shape every other stats service uses, with one
  deviation worth flagging: its headline "best" metric sorts **ascending** (lowest final
  score), not descending like every other game's own best-score metric, since lower is
  actually better here. Tracked stat: `rounds_won` (times this participant went out
  first), surfaced as a second best-metric tile the same "omit rather than show a
  misleading zero" way Best Match omits "Most rounds judged" for a player who's never
  judged.

## 5. Accessibility

Same low-stim system as the rest of the app (see `docs/DEVLOG.md`'s "Site-wide design
system & chrome" section): muted palette, no flashy card-flip/deal animation, no forced
sound, respects `prefers-reduced-motion`. No new accessibility decisions expected here
beyond applying the existing system — though note the center pile and face-up cards
should stay easy to scan given the rule that any player can examine them at any time.

## 6. Deliverables for v1

- `GameType::ClearTheDeck` + `App\Enums\ClearTheDeckPhase`.
- Migration adding `table_face_up`/`table_face_down` (nullable JSON) to `room_seats`.
- `pages::clear-the-deck.room` (round-target stepper, 3–10 player enforcement via a
  host-visible `$startError`, deal-and-create-session on start) and `pages::clear-the-
  deck.play` (the full turn engine from section 4: `playCards()`, pickup/set/wild
  resolution, round-end scoring, `nextRound()`), both online-rooms-only like Best Match
  (no pass-and-play mode — the hidden-per-seat state is the whole point here too).
  Routes: `/clear-the-deck`, `/clear-the-deck/room/{code}`.
- `pages::profile.clear-the-deck.index`/`.show` + `App\Services\Stats\
  ClearTheDeckStatsService`, following the existing per-game profile pattern.
- Homepage card, sidebar link, `<x-player-stats>` wiring — same as every prior game's.
- Test coverage mirroring `tests/Feature/Games/AppleMatchRoomTest.php`'s shape: dealing,
  the 3–10 player range, a full play → set-clear / pickup / normal-continue cycle,
  round-end scoring and multi-round accumulation, going out ending a round immediately,
  wild-Ten always clearing, an explicit assertion that `table_face_down` contents never
  appear in broadcast `GameSession.state`, and a browser test for the phase-gated
  visibility CSS/conditional-rendering bug class `CLAUDE.md`'s "Automated Browser
  Testing" section warns a Livewire-only test can't catch.

---

## Appendix: original rules

### Object

To "go out" by playing all of your cards. And if you're one of the other players, to be
left with as few points in your hand as possible.

### Required

Enough standard playing cards so each player can be dealt 20 cards. For 3, 4, or 5
players, two 52-card decks of playing cards are needed (without jokers). For 6 or 7
players, three decks are needed. For 8, 9, or 10 players, four decks.

### A set

A set is four or more cards of the same value. When this occurs the player can "Clear
the Deck" (remove all the cards in the center pile) and takes an additional turn. Suits
are not used in this game.

### Card values

King is high and Ace is low.

### Preparation

Choose one player to be the dealer. The dealer shuffles the cards and deals four cards
face down to each player, one card at a time going around the table. The deal continues
going around the table with each person receiving four more cards, face up on top of
each down card. Finally, each player is dealt 12 cards, still going around the table,
that they will hold as their hand. Each player will have a total of 20 cards. The
remaining undealt cards become the discard pile.

### Play

The player to the left of the dealer begins by playing as many cards of the same value
as desired from among their hand and their face-up cards in the center of the table
(i.e., two Kings, three nines, five threes, etc.). A face-down card cannot be played
while it is still covered by a face-up card (see note below).

If the player drops a set (four or more cards of same value) they "Clear the Deck,"
placing those cards face down on the discard pile and taking an additional turn. Play
continues clockwise and the next player must play cards of equal or lesser value than
the player before them. If this player creates a set of four or more of the same value
by adding to the previously played cards, they "Clear the Deck," placing the entire
center stack on the discard pile, then take an additional turn.

If a player plays a card of greater value than the previous player, they must take the
entire center stack into their hand, then receive an additional turn.

**Note:** A player can only play a face-down card once it is no longer covered by a
face-up card. At no time can a player look at their face-down card until they choose to
play it by flipping it over. Once revealed, that card must be played. Additional
matching cards from the player's hand or face-up cards can be played along with it. As
in all other play, if it exceeds the value of the previously played card the player
must pick up the center stack. If it is of equal or lesser value than the previously
played card, the game continues. If it creates a set, the player will "Clear the Deck"
and receive an additional turn.

Play continues until someone "goes out" by playing all 20 of their cards. At that point
the round ends.

**Note:** During play the number of cards in each player's hand must remain visible or
be shown upon request. Also, cards played in the center must remain visible and can be
examined by any player at any time.

### Wild

Tens are considered wild cards, in that they can be played to "Clear the Deck" at any
time, with the player receiving an additional turn. However, any Tens held in a
player's hand when play ends count as 25 points against that player.

### Scoring

The player who goes out receives zero points for that round. The other players are
awarded points based on the values of the cards remaining in their hand (including
those on the table, both face up and face down).

Numbered cards are their face value. A King is 13 points, a Queen is 12, a Jack is 11,
and an Ace is 1 point. Tens held at the end are 25 points.

After each player is awarded their points for the round, the deal moves clockwise to
the next player and the game continues. Players can pre-designate how many rounds
constitute an entire game, or play until someone reaches a certain high score. At the
end, the player with the lowest total score is the winner.
