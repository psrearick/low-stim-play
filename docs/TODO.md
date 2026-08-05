# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Numbers below are both the task ID and the suggested work order — when a task is completed and removed, renumber the rest so the list stays contiguous. Reference a task elsewhere as "TODO #N".

## Order of Work

1. [Allow hosting online rooms while logged out](#1-allow-hosting-online-rooms-while-logged-out)
2. [Guest account cleanup job](#2-guest-account-cleanup-job)
3. [Rename games to avoid trademark issues](#3-rename-games-to-avoid-trademark-issues)
4. [Add Farkle](#4-add-farkle)
5. [Add Apples to Apples](#5-add-apples-to-apples)
6. [Add Clear the Deck](#6-add-clear-the-deck)
7. [Two-factor authentication toggle](#7-two-factor-authentication-toggle)
8. [Image avatars](#8-image-avatars)
9. [Friends activity](#9-friends-activity)
10. [Rename "Home"/"Dashboard"](#10-rename-homedashboard)

## Tasks

### 1. Allow hosting online rooms while logged out

Currently only logged-in users can host an online room. Allow hosting by users who aren't logged in.

**Note:** this will increase the number of guest accounts created — see #2.

### 2. Guest account cleanup job

`User::createGuest()` accounts (created when someone joins a Boggle room without registering) are never deleted. Not a real problem at current scale, but #1 will make it worse. Add a scheduled job, e.g. a command that runs `DELETE FROM users WHERE is_guest AND created_at < ...` on a schedule.

### 3. Rename games to avoid trademark issues

Rename the games everywhere a user can see the name (in-code naming isn't important).

**Do this before #5:** the planned "Apples to Apples" game is itself a still-active trademark — better to land on its final in-app display name once, here, than to build it under the trademarked name and rename it again later.

### 4. Add Farkle

A new dice game. See [`docs/specs/farkle.md`](specs/farkle.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from.

### 5. Add Apples to Apples

A new card-matching game. See [`docs/specs/apples-to-apples.md`](specs/apples-to-apples.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from. Online-room support (per-seat, decoupled from any specific game — see `docs/DEVLOG.md`'s "Room system" section) is already there to build on. Should ship under its final, non-trademarked in-app name (see #3). Shares its "private per-seat view" problem with Clear the Deck (#6) — whichever gets fully specced first should set the pattern the other reuses.

### 6. Add Clear the Deck

A new turn-based, multi-deck shedding card game. See [`docs/specs/clear-the-deck.md`](specs/clear-the-deck.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from. Shares Apples to Apples' (#5) need for a private per-seat view in online rooms, plus a harder problem neither other game has: face-down cards whose identity is hidden even from their own owner until played.

### 7. Two-factor authentication toggle

`Features::twoFactorAuthentication()` is currently disabled in `config/fortify.php`. Enable it so users can turn on 2FA manually.

### 8. Image avatars

`<x-avatar>` currently renders a generated initial-letter badge. Allow users to upload an image, or integrate Gravatar, as an alternative.

### 9. Friends activity

The original app plan mentioned a friends-activity feed on the dashboard once, but no friends/social-graph table (follow requests? mutual-only follows?) exists anywhere in the schema. Needs an actual design decision — what "friends" means here — before any building starts.

### 10. Rename "Home"/"Dashboard"

The navigation redesign (see `docs/DEVLOG.md`'s "Navigation" section) deliberately deferred this — it only makes sense once the new sidebar's structure clarifies each page's actual role, which it now does. "Home" (`/`, the public game-picker page) and "Dashboard" (`/dashboard`, the signed-in stats/nav hub) still read as near-synonyms. Needs actual new names, not just a decision that they should change.
