# Apples to Apples — Build Spec (skeleton)

Status: **not ready to build from.** This is a skeleton laying out the shape a spec
needs, with the open design questions flagged inline. Fill in the open questions, then
this status line can be removed. See `docs/TODO.md` #19.

**Naming:** "Apples to Apples" is an active trademark (Mattel / Out of the Box Games).
Per `docs/TODO.md` #17, this game should ship under a different in-app display name
from day one — decide the name before or alongside writing this spec, not after
building it.

---

## 1. Core design decision (read first)

Unlike Family Feud (one shared screen, no hidden information, because an LLM replaces
the human judge), Apples to Apples has real per-player hidden information: each player
holds a private hand of red (adjective) cards, and a judge role rotates each round.
That's structurally the opposite of Family Feud's single-screen model, so **don't**
default to reusing that pattern here. Decide up front whether this plays as:

- **Pass-and-play on one device** — matching Yahtzee's local multiplayer pattern, but
  awkward for a game whose whole point is a *private* hand each player picks from
  without others seeing.
- **Online rooms with a private per-player view** — each seated player sees only their
  own hand on their own device; the shared room state (green card in play, judge,
  submitted-but-unrevealed red cards) is public. This is the natural fit for the game's
  actual information structure, but depends on real per-player state, which is a
  bigger lift than Family Feud's online rooms (see `docs/TODO.md` #14 — decoupling
  rooms from games should probably land first if this is the direction).

This decision drives everything else below and should be made first.

## 2. Open questions

- **Card content.** Classic Apples to Apples needs a deck of green (noun/adjective
  prompt) cards and red (adjective) cards. Options: a hand-curated static list (like
  Family Feud's question bank), or LLM-generated on the fly (like Family Feud's
  judging call, but generating content instead of judging it — a different use of the
  same `JudgeService`-style pattern in `app/Services/FamilyFeud/JudgeService.php`).
  Pick one; a hybrid (static seed list + LLM top-up) is also possible but should be a
  deliberate choice, not a default.
- **Judging.** In the physical game, the judge is a human player who picks their
  favorite submitted card — fully subjective, no correctness check needed. Does this
  app's version keep a human judge (rotating each round, similar to Family Feud's
  controlling-team rotation), or is there any role for an LLM here? (Likely no LLM
  judging is needed at all, unlike Family Feud — confirm.)
- **Player count.** Needs a minimum (classic game needs 4+ to be interesting: 1 judge +
  3+ submitters). Decide the app's minimum/maximum and how it's enforced in a room.
  Depends on the pass-and-play vs. online-rooms decision in section 1.
- **Round/game length & scoring.** Classic play is first-to-N green cards won, or a
  fixed number of rounds. Confirm which, and N.
- **Hand size and card replenishment.** How many red cards per hand, and how hands
  refill after a card is played.

## 3. Data model (draft once the above is answered)

Depends heavily on section 1's pass-and-play-vs-online-rooms decision. If online rooms:
likely needs per-player hidden hand state layered on top of whatever `Room`/`RoomSeat`/
`GameSession` shape exists after `docs/TODO.md` #14, plus a `GameType::AppleMatch` (or
similar, post-rename) enum case and an `AppleMatchStatsService` following the
`Stats/*Service` pattern in `app/Services/Stats/`.

## 4. Accessibility

Follows the same low-stim design system as every other game in this app (see
`docs/DEVLOG.md`'s "Site-wide design system & chrome" section) — muted palette, no
flashing/bouncing card-reveal animation, no autoplay sound, respects
`prefers-reduced-motion`. No new accessibility decisions expected here beyond applying
the existing system.

## 5. Deliverables for v1

TBD once sections 1–3 above are settled.
