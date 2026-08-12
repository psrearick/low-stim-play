# Clear the Deck — Build Spec (skeleton)

Status: **not ready to build from.** This is a skeleton laying out the shape a spec
needs, with the open design questions flagged inline. Fill in the open questions, then
this status line can be removed. See `docs/TODO.md` #20. Rules as originally provided
are kept verbatim in the [Appendix](#appendix-original-rules) below.

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

## 3. Open questions (must be answered before implementation)

- **Player count range.** The physical rules only cover 3–10 (below 3 the deck-scaling
  table breaks down and isn't defined). Confirm the app enforces a 3–10 seat range.
- **Round count / game length.** The rules say players "pre-designate" a number of
  rounds or a target score. Confirm this app exposes that as a setting when a room is
  created, and pick a sensible default.
- **Face-down card identity storage.** Confirm the intended model explicitly: cards are
  dealt (assigned) at deal time and stored server-side, invisible to every client
  including the owner's, and revealed to that player (and only that player, and only at
  that moment) the instant they choose to play one blind — not generated randomly at
  flip-time. This is the app's first "secret from its own owner" state; get the
  storage/reveal contract nailed down before writing any code around it.
- **Set eligibility.** The rules don't explicitly say whether a "Clear the Deck" set
  (four-of-a-kind) can be made entirely from cards already face-up on the table, or
  must include at least one card newly played from the current player's hand. Confirm
  the intended ruling before coding it either way.
- **Wild Ten as a non-clearing play.** The rules only describe Tens clearing the deck
  "at any time." Unclear whether a Ten can also be played as an ordinary
  equal-or-lesser-value card (i.e., used as a low card without triggering a clear), or
  whether every Ten play always clears. Confirm before implementing wild-card logic.
- **AFK / turn-timer handling.** The physical game has no time limit; decide whether
  this app needs one for online rooms (e.g. auto-skip after N seconds), consistent with
  whatever pattern the other online-room games (or `docs/TODO.md` #14's decoupled room
  system) settle on.
- **Name/trademark check.** Lower risk than "Apples to Apples" (now "Best Match," see
  `docs/specs/apple-match.md`) was, but not yet confirmed either way — worth a quick
  check before shipping the name as-is, following `docs/DEVLOG.md`'s "Game renaming"
  section's pattern if a change turns out to be needed.

## 4. Data model (draft once section 3 is answered)

Likely needs: a `GameType::ClearTheDeck` (or renamed equivalent) enum case; a
room/seat-backed session with, per seat, a private hand, a face-up table stack (public
identities), and a face-down table stack (public existence/count only, private
identity-on-reveal); a shared center pile; a turn-order/current-player pointer and
"extra turn pending" flag (sets and wild-Ten clears both grant another turn); and a
stats service following the `Stats/*Service` pattern in `app/Services/Stats/` (e.g.
round wins, average round score, times gone out first).

## 5. Accessibility

Same low-stim system as the rest of the app (see `docs/DEVLOG.md`'s "Site-wide design
system & chrome" section): muted palette, no flashy card-flip/deal animation, no forced
sound, respects `prefers-reduced-motion`. No new accessibility decisions expected here
beyond applying the existing system — though note the center pile and face-up cards
should stay easy to scan given the rule that any player can examine them at any time.

## 6. Deliverables for v1

TBD once section 3's open questions are settled.

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
