# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/CHANGELOG.md` and delete it from this file rather than checking it off in place.

## Bug: a Boggle room game isn't saved to a signed-in non-host's profile

Reported: playing an online Boggle room game as a joiner (not the host), while signed in, doesn't show up in that player's own recent games/stats afterward — even though they were logged in the whole time, not an anonymous guest.

Investigation so far (not fully diagnosed):

- The participant-creation code itself (`pages::boggle.play`'s `finishGame()`) doesn't special-case the host at all — every participant gets `'user_id' => Auth::id()` / `'guest_name' => Auth::check() ? null : 'Guest'` identically, host or not. This exact scenario (a second, real-account, non-host player in a room) is also directly covered by an existing passing test (`tests/Feature/Games/BoggleRoomTest.php`, "players in a room play the same board and are ranked against each other") — but that test drives both players calling `endGame()` **directly** themselves, which isn't necessarily how it plays out for a real non-host player in practice.
- **Strongest lead:** a non-host player who doesn't proactively click "End Game" themselves — e.g. they just wait for the host to end the session for everyone — relies entirely on the `RoomGameEnded` broadcast reaching their own browser and triggering `onRoomGameEnded()` to create *their* `GameParticipant` row. If that broadcast doesn't reliably reach every connected client outside of local testing, the non-host's participant row would simply never get created at all (not mis-attributed — just missing), which would look exactly like "my game isn't saved." This session already found and fixed more than one broadcast-reliability/ordering bug in this exact family (see `docs/CHANGELOG.md`'s Phase 8/"Later additions" notes on `RoomGameStarting`/`ShouldBroadcastNow`) that only ever showed up once actually deployed, not locally — `RoomGameEnded` reaching every player reliably (not just the one who manually clicked "End Game") hasn't specifically been re-verified the same way.
- Worth checking as a starting point: does a non-host player who never clicks "End Game" themselves ever get a `GameParticipant` row created at all, in a real (deployed, not local) multi-device session? If not, that's the bug — not an attribution mixup, but the cascade-triggered save path for everyone except whoever manually ends the game.

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

## Homepage: move auth links into the top bar

`resources/views/welcome.blade.php` currently puts "Create an account"/"sign in" (guests) or "Your dashboard" (logged in) as a plain text line below the game grid (`.home-account-links`), not in the shared button-bar every other page uses (`resources/views/layouts/app.blade.php`'s `.button-bar`). There's also no direct logout button anywhere on the homepage today (logout only exists as a form on `/dashboard`).

Move these into the top bar, matching how other pages put page-specific actions there (either via the `.right` side of the button-bar directly, since `welcome.blade.php` isn't a Livewire component and doesn't need `@teleport`, or following the `$rules`/`$fullscreenToggle` named-slot pattern already used for other static per-page bar content). Needs both states: guest (Sign In / Create Account) and authenticated (Dashboard + Log Out).

## Smaller, previously-flagged gaps

- **Friends activity** — the original plan mentioned it once for the dashboard, but no friends/social-graph table (requests? follows? mutual only?) exists anywhere in the schema. Needs an actual design decision before building, not just an omission to quietly fill in.
- **Image avatars** — `<x-avatar>` is a generated initial-letter badge; no upload or Gravatar integration was ever specced. Low priority, cosmetic.
- **Guest account cleanup** — `User::createGuest()` accounts (from joining a Boggle room without registering) are never deleted. Fine at this app's current scale; a real cost if it ever needs to scale up. Fix would be a scheduled job: `DELETE FROM users WHERE is_guest AND created_at < ...`.
- **`wire:confirm` native browser dialogs** — Skip Question/End Game/New Game confirmations across all three games use the browser's native `confirm()`. Legacy's own Family Feud spec explicitly calls this out as an accessibility violation for this app's low-stim goals ("bright OS-default modal"), but it was kept for now as the simplest option and to match existing precedent. Worth reconsidering as one cross-cutting change (a custom low-stim confirm dialog) since it'd affect every game at once, not just one.
- **Two-factor authentication** — `Features::twoFactorAuthentication()` is left disabled in `config/fortify.php`. Never requested; revisit only if wanted.
- **Yahtzee Sizing** — Yahtzee should all fit on one screen on desktop so it does not need to be scrolled.
