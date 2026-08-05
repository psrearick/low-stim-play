# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Numbers below are both the task ID and the suggested work order — when a task is completed and removed, renumber the rest so the list stays contiguous. Reference a task elsewhere as "TODO #N".

## Order of Work

1. [Fix Family Feud home-page card description](#1-fix-family-feud-home-page-card-description)
2. [Investigate frequent mobile sign-outs](#2-investigate-frequent-mobile-sign-outs)
3. [Redesign site navigation](#3-redesign-site-navigation)
4. [Decouple online rooms from specific games](#4-decouple-online-rooms-from-specific-games)
5. [Allow hosting online rooms while logged out](#5-allow-hosting-online-rooms-while-logged-out)
6. [Guest account cleanup job](#6-guest-account-cleanup-job)
7. [Rename games to avoid trademark issues](#7-rename-games-to-avoid-trademark-issues)
8. [Add Farkle](#8-add-farkle)
9. [Add Apples to Apples](#9-add-apples-to-apples)
10. [Add Clear the Deck](#10-add-clear-the-deck)
11. [Two-factor authentication toggle](#11-two-factor-authentication-toggle)
12. [Image avatars](#12-image-avatars)
13. [Friends activity](#13-friends-activity)

## Tasks

### 1. Fix Family Feud home-page card description

The Family Feud game card on the home page doesn't currently describe what the game is. Update its copy.

### 2. Investigate frequent mobile sign-outs

Mobile users get signed out automatically and often. Desktop sessions seem to last longer, though that's unconfirmed. PWA vs. regular browser doesn't seem to change the behavior. Needs investigation into session/cookie lifetime handling on mobile.

### 3. Redesign site navigation

The current navigation is inconsistent and confusing in several ways:

- Game-type dashboard pages (e.g. `profile/boggle`) get back to the dashboard via the header's Dashboard button; an individual game page (e.g. `profile/boggle/91`) loses that button and instead uses a text link ("← Boggle Stats") at the top of the page.
- The top bar's buttons change from page to page, which is disorienting.
- "Edit Profile" living in the nav bar on the Dashboard page feels out of place; unclear where it belongs instead.
- "Dashboard" and "Log Out" occupy the same nav position on different screens.
- "Home" and "Dashboard" read as near-synonyms, adding to the confusion.

Needs an overall navigation structure (e.g. a persistent sidebar on desktop) instead of page-by-page patches, and probably a rename of "Home"/"Dashboard" once the structure makes their roles distinct. Some screens may benefit from collapsing multiple options into a nav dropdown.

### 4. Decouple online rooms from specific games

Online rooms are currently game-specific — switching from one game to another (e.g. Boggle to Yahtzee) requires creating a new room. Decouple rooms from games so players can stay in one room and switch between games.

**Why before #9:** Apples to Apples is inherently multiplayer and will want online-room support. Building it after this decoupling avoids another one-off, game-specific room implementation — which is what happened when Family Feud's online rooms were built as their own thing (see `docs/DEVLOG.md`).

### 5. Allow hosting online rooms while logged out

Currently only logged-in users can host an online room. Allow hosting by users who aren't logged in.

**Note:** this will increase the number of guest accounts created — see #6.

### 6. Guest account cleanup job

`User::createGuest()` accounts (created when someone joins a Boggle room without registering) are never deleted. Not a real problem at current scale, but #5 will make it worse. Add a scheduled job, e.g. a command that runs `DELETE FROM users WHERE is_guest AND created_at < ...` on a schedule.

### 7. Rename games to avoid trademark issues

Rename the games everywhere a user can see the name (in-code naming isn't important).

**Do this before #9:** the planned "Apples to Apples" game is itself a still-active trademark — better to land on its final in-app display name once, here, than to build it under the trademarked name and rename it again later.

### 8. Add Farkle

A new dice game. See [`docs/specs/farkle.md`](specs/farkle.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from.

### 9. Add Apples to Apples

A new card-matching game. See [`docs/specs/apples-to-apples.md`](specs/apples-to-apples.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from. Likely wants online-room support from day one (see #4), and should ship under its final, non-trademarked in-app name (see #7). Shares its "private per-seat view" problem with Clear the Deck (#10) — whichever gets fully specced first should set the pattern the other reuses.

### 10. Add Clear the Deck

A new turn-based, multi-deck shedding card game. See [`docs/specs/clear-the-deck.md`](specs/clear-the-deck.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from. Shares Apples to Apples' (#9) need for a private per-seat view in online rooms, plus a harder problem neither other game has: face-down cards whose identity is hidden even from their own owner until played. Depends on #4 the same way #9 does.

### 11. Two-factor authentication toggle

`Features::twoFactorAuthentication()` is currently disabled in `config/fortify.php`. Enable it so users can turn on 2FA manually.

### 12. Image avatars

`<x-avatar>` currently renders a generated initial-letter badge. Allow users to upload an image, or integrate Gravatar, as an alternative.

### 13. Friends activity

The original app plan mentioned a friends-activity feed on the dashboard once, but no friends/social-graph table (follow requests? mutual-only follows?) exists anywhere in the schema. Needs an actual design decision — what "friends" means here — before any building starts.
