# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/CHANGELOG.md` and delete it from this file rather than checking it off in place.

## Games

- Apples to Apples
- Farkle

## Smaller Items

- Online rooms are game specific. Changing from one game to another, like boggle to Yahtzee, requires creating a new room. Consider decoupling rooms from games so players can stay in one room and switch between games.
- Allow hosting online rooms by users who are not logged in.
- Change the description of the Family Feud game card on the home page as it does not currently describe what the game is.
- Rename the games everywhere to avoid trademark issues. In code isn't important, but what's visible to the user should be changed.
- Mobile users are automatically signed out frequently. I'm not sure if desktop remains logged in longer, but I think they are. Using a PWA vs. actual browser seems to have no effect.
- Stats
    - There should be an "All Games" page with a paginated table of all games (regardless of type) with basic stats, like the recent games table on the dashboard. It should be linked to in the dashboard.
    - Boggle stats "All Games" table should have a column to show win or loss for online room games.
    - Dashboard boggle card has win rate. This should be calculated only using online room games, and only ones where at least 1 player has more than zero points.
    - On mobile, some stats tables log do not show everything and do not scroll. Boggle's "All games" table is confirmed. It should scroll horizontally.
    - Individual game page, like a single boggle game, should show if the player won or lost. It should only show this if it was an online room game, not a solo game.
    - Some boggle games are showing stats where the score is incorrect. This might have been a migration issue with older games during development. Confirm the logic is math is correct now.
- Investigate a better method for navigation. Game type pages in the dashboard (like the Boggle page `profile/boggle`), returning to the dashboard is done by clicking the dashboard button in the header. For an individual game (like `profile/boggle/91`) the dashboard button is gone and returning to the boggle page is done by a text link ("← Boggle Stats") at the top of the page. This discrepancy and the top bar changing buttons throughout the page makes navigation confusing. There needs to be a better structure for navigation. Perhaps using s sidebar on desktop. "Edit Profile" being in the nav bar on the "Dashboard" page seems weird too. I'm not sure where it should be. "Dashboard" and "Log Out" being in the same place on different screens is confusing. Maybe some places might benefit from multiple options being collapsed into a navbar dropdown. "Home" and "Dashboard" are confusing names as they seem like they should be the same thing. Navigation might fix this and renaming them.
- The Edit Profile page should have a button to click to clear all stats, with a standard app dialog (not native browser) 
- The Edit Profile page should have a delete profile button to completely delete an account with a confirm dialog. This can be hard delete or soft delete. If someone creates an account with a deleted email creates an account with the same email, in needs to work, and it needs to create a new account, not use the old account.
- **`wire:confirm` native browser dialogs** — Skip Question/End Game/New Game confirmations across all three games use the browser's native `confirm()`. Legacy's own Family Feud spec explicitly calls this out as an accessibility violation for this app's low-stim goals ("bright OS-default modal"), but it was kept for now as the simplest option and to match existing precedent. Create a custom low-stim dialog component that is used as base dialog that can be extended for every dialog in the app. If there are enough confirmations in the app, an extended confirmation dialog might be worth creating as a base for all confirmation dialogs.
- **Guest account cleanup** — `User::createGuest()` accounts (from joining a Boggle room without registering) are never deleted. Fine at this app's current scale; a real cost if it ever needs to scale up. Fix would be a scheduled job: `DELETE FROM users WHERE is_guest AND created_at < ...`.
- **Friends activity** — the original plan mentioned it once for the dashboard, but no friends/social-graph table (requests? follows? mutual only?) exists anywhere in the schema. Needs an actual design decision before building, not just an omission to quietly fill in.
- **Image avatars** — `<x-avatar>` is a generated initial-letter badge; no upload or Gravatar integration was ever specced. Low priority, cosmetic.
- **Two-factor authentication** — `Features::twoFactorAuthentication()` is left disabled in `config/fortify.php`. Never requested; revisit only if wanted.
