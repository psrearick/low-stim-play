# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/CHANGELOG.md` and delete it from this file rather than checking it off in place.

## Online rooms for Family Feud (host/TV screen + per-player phones)

Yahtzee's own online-room slice of this is done (see `docs/CHANGELOG.md`) and proved out all the genuinely new shared infrastructure this needs — `GameSession.state`, `RoomSeat`, `RoomGameStateUpdated`, seat-gated turn authorization, the spectator/host view, and the mobile scoreboard drawer are all real, tested, and 100% reusable as-is (game-agnostic by construction, same as `Room`/`RoomStatus`/`⚡game-lobby.blade.php` already were). Family Feud needs its own follow-up pass to actually wire that infrastructure in, plus what's still genuinely Feud-specific:

- Teams have players (not just a captain), and players take turns entering guesses from their own phone. Steal attempts specifically require the team **captain's** device — needs a `team_index`/`is_captain` addition to `room_seats` (deliberately left off that table for Yahtzee, which has no teams) and per-seat/per-captain gating on `submitGuess()`, which currently has zero authorization at all.
- A **rotating turn pointer** for within-team player turn-taking (whose turn it is to submit the next guess), analogous to Yahtzee's own `currentSeat`.
- The judge call (`JudgeService::judge()`, a real blocking OpenRouter request) happens inside whichever seat's own guess triggered it, exactly like local pass-and-play already does — no new queue infrastructure needed, just broadcasting the result via `RoomGameStateUpdated` once it resolves so every other seat/spectator sees it too.
- Everything must keep working exactly as it does today when no additional devices join — same additive-only constraint that already held for Yahtzee.

Smaller pass than the original scope; plan it properly (`EnterPlanMode`) before starting, but it's no longer the ground-up multi-session project the combined Yahtzee+Feud version was.

## Smaller Items

- In Yahtzee room, if it's on the watch only mode, not player, it should show the current scores for each player, but not the possible scores. Currently, if the active player is the one who using the watch only mode, their scores show all the possible scores for the current dice, not just the scores chosen in previous rounds. It should only show the final scores from previous rounds if in watch only mode
- In Yahtzee room mobile, the Scores view should show the current die roll and round as well. The scores can be smaller if needed for it to fit since the user is not interacting with the scores, just viewing them. The dice can be a little smaller too, if needed for space on that screen.
- **Friends activity** — the original plan mentioned it once for the dashboard, but no friends/social-graph table (requests? follows? mutual only?) exists anywhere in the schema. Needs an actual design decision before building, not just an omission to quietly fill in.
- **Image avatars** — `<x-avatar>` is a generated initial-letter badge; no upload or Gravatar integration was ever specced. Low priority, cosmetic.
- **Guest account cleanup** — `User::createGuest()` accounts (from joining a Boggle room without registering) are never deleted. Fine at this app's current scale; a real cost if it ever needs to scale up. Fix would be a scheduled job: `DELETE FROM users WHERE is_guest AND created_at < ...`.
- **`wire:confirm` native browser dialogs** — Skip Question/End Game/New Game confirmations across all three games use the browser's native `confirm()`. Legacy's own Family Feud spec explicitly calls this out as an accessibility violation for this app's low-stim goals ("bright OS-default modal"), but it was kept for now as the simplest option and to match existing precedent. Worth reconsidering as one cross-cutting change (a custom low-stim confirm dialog) since it'd affect every game at once, not just one.
- **Two-factor authentication** — `Features::twoFactorAuthentication()` is left disabled in `config/fortify.php`. Never requested; revisit only if wanted.
