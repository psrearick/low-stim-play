# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/CHANGELOG.md` and delete it from this file rather than checking it off in place.

## Smaller Items

- **Friends activity** — the original plan mentioned it once for the dashboard, but no friends/social-graph table (requests? follows? mutual only?) exists anywhere in the schema. Needs an actual design decision before building, not just an omission to quietly fill in.
- **Image avatars** — `<x-avatar>` is a generated initial-letter badge; no upload or Gravatar integration was ever specced. Low priority, cosmetic.
- **Guest account cleanup** — `User::createGuest()` accounts (from joining a Boggle room without registering) are never deleted. Fine at this app's current scale; a real cost if it ever needs to scale up. Fix would be a scheduled job: `DELETE FROM users WHERE is_guest AND created_at < ...`.
- **`wire:confirm` native browser dialogs** — Skip Question/End Game/New Game confirmations across all three games use the browser's native `confirm()`. Legacy's own Family Feud spec explicitly calls this out as an accessibility violation for this app's low-stim goals ("bright OS-default modal"), but it was kept for now as the simplest option and to match existing precedent. Worth reconsidering as one cross-cutting change (a custom low-stim confirm dialog) since it'd affect every game at once, not just one.
- **Two-factor authentication** — `Features::twoFactorAuthentication()` is left disabled in `config/fortify.php`. Never requested; revisit only if wanted.
