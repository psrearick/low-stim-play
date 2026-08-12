# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Numbers below are both the task ID and the suggested work order — when a task is completed and removed, renumber the rest so the list stays contiguous. Reference a task elsewhere as "TODO #N".

## Order of Work

1. [Add Clear the Deck](#1-add-clear-the-deck)
2. [Two-factor authentication toggle](#2-two-factor-authentication-toggle)
3. [Image avatars](#3-image-avatars)
4. [Friends activity](#4-friends-activity)
5. [Rename "Home"/"Dashboard"](#5-rename-homedashboard)

## Tasks

### 1. Add Clear the Deck

A new turn-based, multi-deck shedding card game. See [`docs/specs/clear-the-deck.md`](specs/clear-the-deck.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from. Best Match (see `docs/DEVLOG.md`'s own section) already set the "private per-seat view" pattern for online rooms — never assign secret state to a rendered Livewire public property outside the one action that needs it, resolve it fresh from `GameSession.state`/the DB instead — that this game's own harder problem (face-down cards whose identity is hidden even from their own owner until played) should reuse.

### 2. Two-factor authentication toggle

`Features::twoFactorAuthentication()` is currently disabled in `config/fortify.php`. Enable it so users can turn on 2FA manually.

### 3. Image avatars

`<x-avatar>` currently renders a generated initial-letter badge. Allow users to upload an image, or integrate Gravatar, as an alternative.

### 4. Friends activity

The original app plan mentioned a friends-activity feed on the dashboard once, but no friends/social-graph table (follow requests? mutual-only follows?) exists anywhere in the schema. Needs an actual design decision — what "friends" means here — before any building starts.

### 5. Rename "Home"/"Dashboard"

The navigation redesign (see `docs/DEVLOG.md`'s "Navigation" section) deliberately deferred this — it only makes sense once the new sidebar's structure clarifies each page's actual role, which it now does. "Home" (`/`, the public game-picker page) and "Dashboard" (`/dashboard`, the signed-in stats/nav hub) still read as near-synonyms. Needs actual new names, not just a decision that they should change.
