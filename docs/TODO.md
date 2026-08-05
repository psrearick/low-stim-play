# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Numbers below are both the task ID and the suggested work order — when a task is completed and removed, renumber the rest so the list stays contiguous. Reference a task elsewhere as "TODO #N".

## Order of Work

1. [Add Farkle](#1-add-farkle)
2. [Add Apples to Apples](#2-add-apples-to-apples)
3. [Add Clear the Deck](#3-add-clear-the-deck)
4. [Two-factor authentication toggle](#4-two-factor-authentication-toggle)
5. [Image avatars](#5-image-avatars)
6. [Friends activity](#6-friends-activity)
7. [Rename "Home"/"Dashboard"](#7-rename-homedashboard)

## Tasks

### 1. Add Farkle

A new dice game. See [`docs/specs/farkle.md`](specs/farkle.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from.

### 2. Add Apples to Apples

A new card-matching game. See [`docs/specs/apples-to-apples.md`](specs/apples-to-apples.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from. Online-room support (per-seat, decoupled from any specific game — see `docs/DEVLOG.md`'s "Room system" section) is already there to build on. "Apples to Apples" is itself a still-active trademark — pick a final, non-trademarked in-app display name as part of this task (see `docs/DEVLOG.md`'s "Game renaming" section for the pattern used for Boggle/Yahtzee/Family Feud) rather than building under the trademarked name and renaming it later. Shares its "private per-seat view" problem with Clear the Deck (#3) — whichever gets fully specced first should set the pattern the other reuses.

### 3. Add Clear the Deck

A new turn-based, multi-deck shedding card game. See [`docs/specs/clear-the-deck.md`](specs/clear-the-deck.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from. Shares Apples to Apples' (#2) need for a private per-seat view in online rooms, plus a harder problem neither other game has: face-down cards whose identity is hidden even from their own owner until played.

### 4. Two-factor authentication toggle

`Features::twoFactorAuthentication()` is currently disabled in `config/fortify.php`. Enable it so users can turn on 2FA manually.

### 5. Image avatars

`<x-avatar>` currently renders a generated initial-letter badge. Allow users to upload an image, or integrate Gravatar, as an alternative.

### 6. Friends activity

The original app plan mentioned a friends-activity feed on the dashboard once, but no friends/social-graph table (follow requests? mutual-only follows?) exists anywhere in the schema. Needs an actual design decision — what "friends" means here — before any building starts.

### 7. Rename "Home"/"Dashboard"

The navigation redesign (see `docs/DEVLOG.md`'s "Navigation" section) deliberately deferred this — it only makes sense once the new sidebar's structure clarifies each page's actual role, which it now does. "Home" (`/`, the public game-picker page) and "Dashboard" (`/dashboard`, the signed-in stats/nav hub) still read as near-synonyms. Needs actual new names, not just a decision that they should change.
