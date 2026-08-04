# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Numbers below are both the task ID and the suggested work order — when a task is completed and removed, renumber the rest so the list stays contiguous. Reference a task elsewhere as "TODO #N".

## Order of Work

1. [Edit Profile: clear all stats](#1-edit-profile-clear-all-stats)
2. [Edit Profile: delete account](#2-edit-profile-delete-account)
3. [Verify Boggle score math on historical games](#3-verify-boggle-score-math-on-historical-games)
4. [Fix Boggle win-rate calculation on the dashboard](#4-fix-boggle-win-rate-calculation-on-the-dashboard)
5. [Add an "All Games" stats page](#5-add-an-all-games-stats-page)
6. [Add win/loss column to Boggle's "All Games" table](#6-add-winloss-column-to-boggles-all-games-table)
7. [Show win/loss on individual game pages](#7-show-winloss-on-individual-game-pages)
8. [Fix stats tables not scrolling on mobile](#8-fix-stats-tables-not-scrolling-on-mobile)
9. [Fix Family Feud home-page card description](#9-fix-family-feud-home-page-card-description)
10. [Investigate frequent mobile sign-outs](#10-investigate-frequent-mobile-sign-outs)
11. [Redesign site navigation](#11-redesign-site-navigation)
12. [Decouple online rooms from specific games](#12-decouple-online-rooms-from-specific-games)
13. [Allow hosting online rooms while logged out](#13-allow-hosting-online-rooms-while-logged-out)
14. [Guest account cleanup job](#14-guest-account-cleanup-job)
15. [Rename games to avoid trademark issues](#15-rename-games-to-avoid-trademark-issues)
16. [Add Farkle](#16-add-farkle)
17. [Add Apples to Apples](#17-add-apples-to-apples)
18. [Add Clear the Deck](#18-add-clear-the-deck)
19. [Two-factor authentication toggle](#19-two-factor-authentication-toggle)
20. [Image avatars](#20-image-avatars)
21. [Friends activity](#21-friends-activity)

## Tasks

### 1. Edit Profile: clear all stats

Add a "Clear all stats" button to the Edit Profile page, gated behind the `<x-confirm-dialog>` component (see `docs/DEVLOG.md`'s "Low-stim confirm dialogs"), that wipes the user's game history/stats.

### 2. Edit Profile: delete account

Add a "Delete profile" button to Edit Profile, gated behind `<x-confirm-dialog>`, that deletes the account. Hard vs. soft delete is still an open decision. Either way: if someone later registers using the same email as a deleted account, registration must succeed and must create a genuinely new account — not reactivate or reuse the old one.

### 3. Verify Boggle score math on historical games

Some Boggle games display incorrect scores in stats, possibly from a migration issue during development. Confirm the current scoring logic is correct, and check whether any historical rows need a data fix.

**Why before #4–#7:** those build more UI on top of these same numbers — worth trusting the underlying data first.

### 4. Fix Boggle win-rate calculation on the dashboard

The Boggle card's win-rate stat on the dashboard should be calculated only from online-room games (excluding solo play), and only from games where at least one player scored above zero points.

### 5. Add an "All Games" stats page

Add a paginated table of all games across every game type (mirroring the recent-games table already on the dashboard), and link to it from the dashboard.

### 6. Add win/loss column to Boggle's "All Games" table

Boggle's own "All Games" table should show a win/loss column for online-room games.

### 7. Show win/loss on individual game pages

An individual game page (e.g. a single Boggle game) should show whether the player won or lost — but only for online-room games, never for solo play.

### 8. Fix stats tables not scrolling on mobile

On mobile, some stats tables don't display every column and don't scroll to reveal the rest. Confirmed on Boggle's "All Games" table — it should scroll horizontally. Check other stats tables for the same issue.

### 9. Fix Family Feud home-page card description

The Family Feud game card on the home page doesn't currently describe what the game is. Update its copy.

### 10. Investigate frequent mobile sign-outs

Mobile users get signed out automatically and often. Desktop sessions seem to last longer, though that's unconfirmed. PWA vs. regular browser doesn't seem to change the behavior. Needs investigation into session/cookie lifetime handling on mobile.

### 11. Redesign site navigation

The current navigation is inconsistent and confusing in several ways:

- Game-type dashboard pages (e.g. `profile/boggle`) get back to the dashboard via the header's Dashboard button; an individual game page (e.g. `profile/boggle/91`) loses that button and instead uses a text link ("← Boggle Stats") at the top of the page.
- The top bar's buttons change from page to page, which is disorienting.
- "Edit Profile" living in the nav bar on the Dashboard page feels out of place; unclear where it belongs instead.
- "Dashboard" and "Log Out" occupy the same nav position on different screens.
- "Home" and "Dashboard" read as near-synonyms, adding to the confusion.

Needs an overall navigation structure (e.g. a persistent sidebar on desktop) instead of page-by-page patches, and probably a rename of "Home"/"Dashboard" once the structure makes their roles distinct. Some screens may benefit from collapsing multiple options into a nav dropdown.

### 12. Decouple online rooms from specific games

Online rooms are currently game-specific — switching from one game to another (e.g. Boggle to Yahtzee) requires creating a new room. Decouple rooms from games so players can stay in one room and switch between games.

**Why before #17:** Apples to Apples is inherently multiplayer and will want online-room support. Building it after this decoupling avoids another one-off, game-specific room implementation — which is what happened when Family Feud's online rooms were built as their own thing (see `docs/DEVLOG.md`).

### 13. Allow hosting online rooms while logged out

Currently only logged-in users can host an online room. Allow hosting by users who aren't logged in.

**Note:** this will increase the number of guest accounts created — see #14.

### 14. Guest account cleanup job

`User::createGuest()` accounts (created when someone joins a Boggle room without registering) are never deleted. Not a real problem at current scale, but #13 will make it worse. Add a scheduled job, e.g. a command that runs `DELETE FROM users WHERE is_guest AND created_at < ...` on a schedule.

### 15. Rename games to avoid trademark issues

Rename the games everywhere a user can see the name (in-code naming isn't important).

**Do this before #17:** the planned "Apples to Apples" game is itself a still-active trademark — better to land on its final in-app display name once, here, than to build it under the trademarked name and rename it again later.

### 16. Add Farkle

A new dice game. See [`docs/specs/farkle.md`](specs/farkle.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from.

### 17. Add Apples to Apples

A new card-matching game. See [`docs/specs/apples-to-apples.md`](specs/apples-to-apples.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from. Likely wants online-room support from day one (see #12), and should ship under its final, non-trademarked in-app name (see #15). Shares its "private per-seat view" problem with Clear the Deck (#18) — whichever gets fully specced first should set the pattern the other reuses.

### 18. Add Clear the Deck

A new turn-based, multi-deck shedding card game. See [`docs/specs/clear-the-deck.md`](specs/clear-the-deck.md) for the build spec — currently a skeleton with open design questions, not yet ready to build from. Shares Apples to Apples' (#17) need for a private per-seat view in online rooms, plus a harder problem neither other game has: face-down cards whose identity is hidden even from their own owner until played. Depends on #12 the same way #17 does.

### 19. Two-factor authentication toggle

`Features::twoFactorAuthentication()` is currently disabled in `config/fortify.php`. Enable it so users can turn on 2FA manually.

### 20. Image avatars

`<x-avatar>` currently renders a generated initial-letter badge. Allow users to upload an image, or integrate Gravatar, as an alternative.

### 21. Friends activity

The original app plan mentioned a friends-activity feed on the dashboard once, but no friends/social-graph table (follow requests? mutual-only follows?) exists anywhere in the schema. Needs an actual design decision — what "friends" means here — before any building starts.
