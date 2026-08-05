# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Numbers below are both the task ID and the suggested work order — when a task is completed and removed, renumber the rest so the list stays contiguous. Reference a task elsewhere as "TODO #N".

## Order of Work

1. [Add an "All Games" stats page](#1-add-an-all-games-stats-page)
2. [Add win/loss column to Boggle's "All Games" table](#2-add-winloss-column-to-boggles-all-games-table)
3. [Show win/loss on individual game pages](#3-show-winloss-on-individual-game-pages)
4. [Fix stats tables not scrolling on mobile](#4-fix-stats-tables-not-scrolling-on-mobile)
5. [Fix Family Feud home-page card description](#5-fix-family-feud-home-page-card-description)
6. [Investigate frequent mobile sign-outs](#6-investigate-frequent-mobile-sign-outs)
7. [Redesign site navigation](#7-redesign-site-navigation)
8. [Decouple online rooms from specific games](#8-decouple-online-rooms-from-specific-games)
9. [Allow hosting online rooms while logged out](#9-allow-hosting-online-rooms-while-logged-out)
10. [Guest account cleanup job](#10-guest-account-cleanup-job)
11. [Rename games to avoid trademark issues](#11-rename-games-to-avoid-trademark-issues)
12. [Add Farkle](#12-add-farkle)
13. [Add Apples to Apples](#13-add-apples-to-apples)
14. [Add Clear the Deck](#14-add-clear-the-deck)
15. [Two-factor authentication toggle](#15-two-factor-authentication-toggle)
16. [Image avatars](#16-image-avatars)
17. [Friends activity](#17-friends-activity)

## Tasks

### 1. Add an "All Games" stats page

Add a paginated table of all games across every game type (mirroring the recent-games table already on the dashboard), and link to it from the dashboard.

### 2. Add win/loss column to Boggle's "All Games" table

Boggle's own "All Games" table should show a win/loss column for online-room games.

### 3. Show win/loss on individual game pages

An individual game page (e.g. a single Boggle game) should show whether the player won or lost — but only for online-room games, never for solo play.

### 4. Fix stats tables not scrolling on mobile

On mobile, some stats tables don't display every column and don't scroll to reveal the rest. Confirmed on Boggle's "All Games" table — it should scroll horizontally. Check other stats tables for the same issue.

### 5. Fix Family Feud home-page card description

The Family Feud game card on the home page doesn't currently describe what the game is. Update its copy.

### 6. Investigate frequent mobile sign-outs

Mobile users get signed out automatically and often. Desktop sessions seem to last longer, though that's unconfirmed. PWA vs. regular browser doesn't seem to change the behavior. Needs investigation into session/cookie lifetime handling on mobile.

### 7. Redesign site navigation

The current navigation is inconsistent and confusing in several ways:

- Game-type dashboard pages (e.g. `profile/boggle`) get back to the dashboard via the header's Dashboard button; an individual game page (e.g. `profile/boggle/91`) loses that button and instead uses a text link ("← Boggle Stats") at the top of the page.
- The top bar's buttons change from page to page, which is disorienting.
- "Edit Profile" living in the nav bar on the Dashboard page feels out of place; unclear where it belongs instead.
- "Dashboard" and "Log Out" occupy the same nav position on different screens.
- "Home" and "Dashboard" read as near-synonyms, adding to the confusion.

Needs an overall navigation structure (e.g. a persistent sidebar on desktop) instead of page-by-page patches, and probably a rename of "Home"/"Dashboard" once the structure makes their roles distinct. Some screens may benefit from collapsing multiple options into a nav dropdown.

### 8. Decouple online rooms from specific games

Online rooms are currently game-specific — switching from one game to another (e.g. Boggle to Yahtzee) requires creating a new room. Decouple rooms from games so players can stay in one room and switch between games.

**Why before #13:** Apples to Apples is inherently multiplayer and will want online-room support. Building it after this decoupling avoids another one-off, game-specific room implementation — which is what happened when Family Feud's online rooms were built as their own thing (see `docs/DEVLOG.md`).

### 9. Allow hosting online rooms while logged out

Currently only logged-in users can host an online room. Allow hosting by users who aren't logged in.

**Note:** this will increase the number of guest accounts created — see #10.

### 10. Guest account cleanup job

`User::createGuest()` accounts (created when someone joins a Boggle room without registering) are never deleted. Not a real problem at current scale, but #9 will make it worse. Add a scheduled job, e.g. a command that runs `DELETE FROM users WHERE is_guest AND created_at < ...` on a schedule.

### 11. Rename games to avoid trademark issues

Rename the games everywhere a user can see the name (in-code naming isn't important).

**Do this before #13:** the planned "Apples to Apples" game is itself a still-active trademark — better to land on its final in-app display name once, here, than to build it under the trademarked name and rename it again later.

### 12. Add Farkle

A new dice game. See [`docs/specs/farkle.md`](specs/farkle.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from.

### 13. Add Apples to Apples

A new card-matching game. See [`docs/specs/apples-to-apples.md`](specs/apples-to-apples.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from. Likely wants online-room support from day one (see #8), and should ship under its final, non-trademarked in-app name (see #11). Shares its "private per-seat view" problem with Clear the Deck (#14) — whichever gets fully specced first should set the pattern the other reuses.

### 14. Add Clear the Deck

A new turn-based, multi-deck shedding card game. See [`docs/specs/clear-the-deck.md`](specs/clear-the-deck.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from. Shares Apples to Apples' (#13) need for a private per-seat view in online rooms, plus a harder problem neither other game has: face-down cards whose identity is hidden even from their own owner until played. Depends on #8 the same way #13 does.

### 15. Two-factor authentication toggle

`Features::twoFactorAuthentication()` is currently disabled in `config/fortify.php`. Enable it so users can turn on 2FA manually.

### 16. Image avatars

`<x-avatar>` currently renders a generated initial-letter badge. Allow users to upload an image, or integrate Gravatar, as an alternative.

### 17. Friends activity

The original app plan mentioned a friends-activity feed on the dashboard once, but no friends/social-graph table (follow requests? mutual-only follows?) exists anywhere in the schema. Needs an actual design decision — what "friends" means here — before any building starts.
