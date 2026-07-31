# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/CHANGELOG.md` and delete it from this file rather than checking it off in place.

## Online rooms for Yahtzee & Family Feud (host/TV screen + per-player phones)

The big deferred feature. Requested shape, in full:

- A host/guest room system for Yahtzee and Family Feud, matching Boggle's existing online rooms (host creates a room with a code, others join).
- During setup, if the connecting user is logged in, let them declare which player/team is *them* (same idea as the `meIndex`/`meTeamIndex` picker already shipped for local pass-and-play — see `docs/CHANGELOG.md`'s "Later additions" — but for a real multi-device room, each seat should just naturally belong to whichever account occupies it, no guessing needed).
- A "host" screen — e.g. a computer casting to a TV or a video-call screen share — showing every player's scores, dice rolls, and picks, but **no individual turn controls**. The host can also play from their own phone at the same time (so the same person may be logged in on two devices at once: one hosting/spectating, one playing).
- Each player rolls and picks their own score on their own device (phone); only the current player's device can act.
- Must also work **without** a separate host device: a mobile slideover/drawer, triggered by a button, to see everyone's scores at any time.
- Family Feud specifically: teams have players (not just a captain), and players take turns entering guesses from their own phone. Steal attempts specifically require the team **captain's** device.
- Everything must keep working exactly as it does today when no additional devices join — the room system is strictly additive, not a replacement for local pass-and-play.

Where this stands: explored in depth already (see the "Later additions" entry in `docs/CHANGELOG.md`). Confirmed good news — `Room`, `RoomStatus`, `routes/channels.php`'s presence-channel authorization, `resources/views/components/⚡game-lobby.blade.php`, and the `RoomPlayerReadyToggled`/`RoomGameStarting`/`RoomGameEnded` events are all already 100% game-agnostic and reusable as-is (there's no `game_type` column on `Room` at all — it's generic by construction, only ever used for Boggle by convention so far). What's genuinely new and doesn't exist yet in this app:

1. **A persisted, shared, server-authoritative game state** for turn-based play (dice, per-seat scores, whose turn, revealed answers) — Boggle's multiplayer never needed this, since every player just solves the same static board independently and compares scores at the end. Likely a JSON `state` column somewhere (on `GameSession`, or a new table) plus a version/timestamp for basic concurrency safety.
2. **Seat assignment that survives reloads** — presence-channel membership alone isn't enough (a reconnecting phone needs the server to remember "you are Player 2," not just "someone is connected right now"). This needs real persistence, unlike the lobby's transient "ready" flags.
3. **Server-side turn/captain authorization** — right now Family Feud's `submitGuess()` has zero gating on who's allowed to call it (see `docs/CHANGELOG.md`); this needs an actual per-seat/per-captain check before any of this is safe to expose to multiple real devices.
4. **A spectator/host view** — simplest approach identified so far: the same play page, rendered read-only (no roll/score/guess controls) whenever the connected account doesn't occupy any seat in the room.
5. A **rotating turn pointer** for Family Feud's within-team player turn-taking, and a **captain flag** per team (assigned at lobby time, defaulting to the first joiner or a host pick) gating steal-phase submissions specifically.

This is a multi-session project, not a quick pass — plan it properly (probably its own `EnterPlanMode` round) before starting.

## Smaller, previously-flagged gaps

- **Friends activity** — the original plan mentioned it once for the dashboard, but no friends/social-graph table (requests? follows? mutual only?) exists anywhere in the schema. Needs an actual design decision before building, not just an omission to quietly fill in.
- **Image avatars** — `<x-avatar>` is a generated initial-letter badge; no upload or Gravatar integration was ever specced. Low priority, cosmetic.
- **Guest account cleanup** — `User::createGuest()` accounts (from joining a Boggle room without registering) are never deleted. Fine at this app's current scale; a real cost if it ever needs to scale up. Fix would be a scheduled job: `DELETE FROM users WHERE is_guest AND created_at < ...`.
- **`wire:confirm` native browser dialogs** — Skip Question/End Game/New Game confirmations across all three games use the browser's native `confirm()`. Legacy's own Family Feud spec explicitly calls this out as an accessibility violation for this app's low-stim goals ("bright OS-default modal"), but it was kept for now as the simplest option and to match existing precedent. Worth reconsidering as one cross-cutting change (a custom low-stim confirm dialog) since it'd affect every game at once, not just one.
- **Two-factor authentication** — `Features::twoFactorAuthentication()` is left disabled in `config/fortify.php`. Never requested; revisit only if wanted.
