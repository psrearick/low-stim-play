# Farkle — Build Spec (skeleton)

Status: **not ready to build from.** This is a skeleton laying out the shape a Farkle
spec needs, with the open design questions flagged inline. Fill in the open questions,
then this status line can be removed. See `docs/TODO.md` #18.

---

## 1. Core design decision (read first)

Farkle is a solo/turn-based dice game, structurally closer to this app's existing
Yahtzee than to Family Feud: no hidden per-player information, no judge, just dice,
scoring, and turn order. Yahtzee's implementation (`app/Livewire/Yahtzee*`,
`app/Services/Stats/YahtzeeStatsService.php`, its pass-and-play + online-room pages) is
the closest existing reference and should be used as the starting template rather than
designing this from scratch.

## 2. Open questions (must be answered before implementation)

- **Scoring variant.** "Farkle" covers many house-rule variants (straight/3-pair
  bonuses, minimum entry score to get on the board, triple-1s value, 4-6-of-a-kind
  scaling, etc.). Which exact rule set does this app use? Pick one and write it out in
  full below — don't leave scoring ambiguous, it's the whole game.
- **Target score / win condition.** Classic Farkle plays to 10,000 with a final-round
  rule once someone crosses the threshold. Confirm the target and whether the
  final-round rule applies.
- **Player count & mode.** Yahtzee supports pass-and-play (one device) today, per
  `docs/TODO.md` #14's online-rooms-are-game-specific note. Does Farkle need online
  rooms at launch, or is pass-and-play enough for v1? If online rooms: this depends on
  `docs/TODO.md` #14 (decoupling rooms from games) landing first, or it's another
  one-off game-specific room build like Family Feud's was.
- **"Hot dice" (all six dice score, re-roll all six) — included or not?**
- **Turn UI shape.** Roll → select scoring dice → bank or keep rolling, repeated each
  turn. Confirm this matches the intended interaction before building it.

## 3. Data model (draft once the above is answered)

Likely mirrors Yahtzee's shape: a `GameType::Farkle` enum case, a game-session model
for in-progress state (current player, dice values, held dice, running turn score,
per-player banked totals), and a stats table entry consumed by a new
`FarkleStatsService` following the `YahtzeeStatsService`/`BoggleStatsService` pattern
already in `app/Services/Stats/`.

## 4. Accessibility

Follows the same low-stim design system as every other game in this app (see
`docs/DEVLOG.md`'s "Site-wide design system & chrome" section) — muted palette, no
flashing/bouncing dice animation, no autoplay sound, respects `prefers-reduced-motion`.
No new accessibility decisions expected here beyond applying the existing system.

## 5. Deliverables for v1

TBD once sections 2–3 above are settled — mirror Yahtzee's file layout (Livewire
page(s), a stats service, `<x-player-stats>` wiring, profile stats pages, tests) rather
than inventing a new structure.
