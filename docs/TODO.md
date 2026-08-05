# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Numbers below are both the task ID and the suggested work order — when a task is completed and removed, renumber the rest so the list stays contiguous. Reference a task elsewhere as "TODO #N".

## Order of Work

1. [Make sessions last a long time (maybe indefinitely)](#1-make-sessions-last-a-long-time-maybe-indefinitely)
2. [Redesign site navigation](#2-redesign-site-navigation)
3. [Decouple online rooms from specific games](#3-decouple-online-rooms-from-specific-games)
4. [Allow hosting online rooms while logged out](#4-allow-hosting-online-rooms-while-logged-out)
5. [Guest account cleanup job](#5-guest-account-cleanup-job)
6. [Rename games to avoid trademark issues](#6-rename-games-to-avoid-trademark-issues)
7. [Add Farkle](#7-add-farkle)
8. [Add Apples to Apples](#8-add-apples-to-apples)
9. [Add Clear the Deck](#9-add-clear-the-deck)
10. [Two-factor authentication toggle](#10-two-factor-authentication-toggle)
11. [Image avatars](#11-image-avatars)
12. [Friends activity](#12-friends-activity)

## Tasks

### 1. Make sessions last a long time (maybe indefinitely)

Originally filed as "mobile users get signed out frequently" — that specific bug is **not confirmed** and may already be fixed on its own; don't go hunting for a mobile-only root cause. The actual ask: sessions (mobile and desktop alike) should stay signed in for a long time, possibly indefinitely, rather than expiring on any particular schedule. Check `config/session.php` (`lifetime`, `expire_on_close`) and Fortify/Sanctum's own remember-me behavior, and extend/disable expiry as needed to satisfy "stay logged in a long time."

### 2. Redesign site navigation

The current navigation is inconsistent and confusing in several ways:

- Game-type dashboard pages (e.g. `profile/boggle`) get back to the dashboard via the header's Dashboard button; an individual game page (e.g. `profile/boggle/91`) loses that button and instead uses a text link ("← Boggle Stats") at the top of the page.
- The top bar's buttons change from page to page, which is disorienting.
- "Edit Profile" living in the nav bar on the Dashboard page feels out of place; unclear where it belongs instead.
- "Dashboard" and "Log Out" occupy the same nav position on different screens.
- "Home" and "Dashboard" read as near-synonyms, adding to the confusion.

Needs an overall navigation structure (e.g. a persistent sidebar on desktop) instead of page-by-page patches, and probably a rename of "Home"/"Dashboard" once the structure makes their roles distinct. Some screens may benefit from collapsing multiple options into a nav dropdown.

### 3. Decouple online rooms from specific games

Online rooms are currently game-specific — switching from one game to another (e.g. Boggle to Yahtzee) requires creating a new room. Decouple rooms from games so players can stay in one room and switch between games.

**Why before #8:** Apples to Apples is inherently multiplayer and will want online-room support. Building it after this decoupling avoids another one-off, game-specific room implementation — which is what happened when Family Feud's online rooms were built as their own thing (see `docs/DEVLOG.md`).

### 4. Allow hosting online rooms while logged out

Currently only logged-in users can host an online room. Allow hosting by users who aren't logged in.

**Note:** this will increase the number of guest accounts created — see #5.

### 5. Guest account cleanup job

`User::createGuest()` accounts (created when someone joins a Boggle room without registering) are never deleted. Not a real problem at current scale, but #4 will make it worse. Add a scheduled job, e.g. a command that runs `DELETE FROM users WHERE is_guest AND created_at < ...` on a schedule.

### 6. Rename games to avoid trademark issues

Rename the games everywhere a user can see the name (in-code naming isn't important).

**Do this before #8:** the planned "Apples to Apples" game is itself a still-active trademark — better to land on its final in-app display name once, here, than to build it under the trademarked name and rename it again later.

### 7. Add Farkle

A new dice game. See [`docs/specs/farkle.md`](specs/farkle.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from.

### 8. Add Apples to Apples

A new card-matching game. See [`docs/specs/apples-to-apples.md`](specs/apples-to-apples.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from. Likely wants online-room support from day one (see #3), and should ship under its final, non-trademarked in-app name (see #6). Shares its "private per-seat view" problem with Clear the Deck (#9) — whichever gets fully specced first should set the pattern the other reuses.

### 9. Add Clear the Deck

A new turn-based, multi-deck shedding card game. See [`docs/specs/clear-the-deck.md`](specs/clear-the-deck.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from. Shares Apples to Apples' (#8) need for a private per-seat view in online rooms, plus a harder problem neither other game has: face-down cards whose identity is hidden even from their own owner until played. Depends on #3 the same way #8 does.

### 10. Two-factor authentication toggle

`Features::twoFactorAuthentication()` is currently disabled in `config/fortify.php`. Enable it so users can turn on 2FA manually.

### 11. Image avatars

`<x-avatar>` currently renders a generated initial-letter badge. Allow users to upload an image, or integrate Gravatar, as an alternative.

### 12. Friends activity

The original app plan mentioned a friends-activity feed on the dashboard once, but no friends/social-graph table (follow requests? mutual-only follows?) exists anywhere in the schema. Needs an actual design decision — what "friends" means here — before any building starts.
