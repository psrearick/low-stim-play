# Migration Progress

Tracks status against `docs/plan.md`, in the order listed under "Implementation Order". Update this file whenever a step starts, completes, or gets blocked.

Legend: `[ ]` not started · `[~]` in progress · `[x]` done · `[!]` blocked (see note)

## Phase 1: Infrastructure & Tooling — done

- [x] 1. Custom `docker-compose.yml` + nginx config + PHP Dockerfile
- [x] 2. `.env` updates (mariadb, redis, reverb keys)
- [x] 3. Install packages (Fortify, Livewire, Reverb, Larastan)
- [x] 4. Tooling: `phpstan.neon` (level 5), pre-commit hook, composer scripts (`format`/`analyse`/`check`)
- [x] 5. Design system (CSS tokens, dark mode, no-animation rule)

## Phase 2: Authentication & User Profiles — done

- [x] 6. Auth: Fortify + passkeys config, custom auth views
- [x] 7. Profile extension migration + profile view

## Phase 3: Database Models for Game Tracking — done

- [x] 8. Game tracking models (`GameSession`, `GameParticipant`, factories)

## Phase 5: Room System — done

- [x] 9. Room system (`rooms` table, `GameLobby` component)

## Phase 4: Boggle (First Game Migration)

- [ ] 10. Boggle: service classes, Livewire components, solo → multiplayer → async
- [ ] 11. Boggle tests (unit + feature)

## Phase 6: Subsequent Games

- [ ] 12. Yahtzee: services + Livewire
- [ ] 13. Family Feud: question seeder, judge service, host/display components

## Phase 7: User Profiles & Stats

- [ ] 14. User profile & stats pages

## Notes / Decisions Log

- **2026-07-29** — `laravel/passkeys` is no longer a separate install step. Fortify v1.37 (the version resolved by `composer require laravel/fortify`) depends on `laravel/passkeys` directly and ships native passkey support via `Features::passkeys()` in `config/fortify.php`, including `PasskeyAuthenticatable`/`PasskeyUser` contracts. No standalone `passkeys:install` command exists — it's configured as part of `fortify:install`.
- **2026-07-29** — Did **not** install the standalone `alpinejs` npm package (removed after installing it). Livewire v4 bundles its own Alpine.js instance and injects it via `@livewireScripts`; adding a second copy causes a documented "multiple instances of Alpine" runtime error. Any `x-data` interactivity should rely on Livewire's bundled Alpine. Revisit only if we need Alpine plugins and choose to manually bundle per the Livewire docs.
- **2026-07-29** — `laravel/passkeys` npm package (`@laravel/passkeys`) is still installed — that's the *browser-side* WebAuthn ceremony helper and is unrelated to the Alpine bundling question above.
- **2026-07-29** — Larastan is wired via a direct `includes: vendor/larastan/larastan/extension.neon` in `phpstan.neon` rather than `phpstan/extension-installer`, to avoid adding another Composer plugin to `allow-plugins`.
- **2026-07-29** — Design tokens in `resources/css/app.css` use Tailwind v4's class-based dark mode (`@custom-variant dark (&:where(.dark, .dark *))`). The base `@theme` values are the *light* theme; `.dark` overrides them with the muted palette that is this app's actual default appearance (legacy's default, unmarked theme). Whichever page/layout adds `<html>` needs to default the `dark` class on unless `localStorage.theme === 'light'`, mirroring `legacy/theme.js`, and toggle it from a footer control — this wiring is left for Phase 2 when a real shared layout exists.
- **2026-07-29** — Phase 2.1/2.2 done as part of task 6: ran `fortify:install`, enabled `registration`/`resetPasswords`/`emailVerification`/`updateProfileInformation`/`updatePasswords`/`passkeys` in `config/fortify.php` (left `twoFactorAuthentication` disabled — not in the plan's scope, revisit if requested). `User` model implements `MustVerifyEmail` + Fortify's `PasskeyUser`/`PasskeyAuthenticatable`. There's no standalone `config/passkeys.php` — Fortify owns passkey config directly under `fortify.passkeys` (`relying_party_id`/`allowed_origins`/`timeout`), derived from `APP_URL` (updated to `http://localhost` to match the docker nginx port mapping). Built `resources/views/layouts/app.blade.php` (theme-init script + footer toggle) and `resources/views/auth/{login,register,forgot-password,reset-password,verify-email,confirm-password}.blade.php`, wired via `Fortify::viewPrefix('auth.')`. Passkey WebAuthn ceremonies (login, confirm) are wired via `@laravel/passkeys` vanilla JS API in `resources/js/app.js`, triggered by `[data-passkey-login]`/`[data-passkey-confirm]` buttons — passkey *registration* (`/user/passkeys`) requires an authenticated user, so that UI belongs on the profile page (task 7), not the login/register screens. Added a placeholder `/home` route+view as the post-login destination until Phase 7 builds the real dashboard. Added `tests/Feature/Auth/{RegistrationTest,AuthenticationTest}.php`.
- **2026-07-29** — Task 9 (Room system) required actually finishing Reverb/broadcasting installation, which Phase 1 had only partly done (package installed, nothing configured). `php artisan install:broadcasting --reverb` crashed mid-command in this sandbox (no TTY for its npm step), leaving `config/broadcasting.php` + `routes/channels.php` published but no `REVERB_*` env vars and no `laravel-echo`/`pusher-js` npm packages — and critically, the half-applied state meant `routes/channels.php` unconditionally crashed **every** artisan command (`Broadcast::channel()` eagerly resolves the `reverb` broadcaster, which throws if `REVERB_APP_KEY`/`SECRET`/`ID` are null). Fixed by hand: added `REVERB_APP_ID`/`KEY`/`SECRET` + `REVERB_HOST=reverb`/`PORT=8080`/`SCHEME=http` (server-side, internal docker network) and mirrored `VITE_REVERB_*` (client-side, `VITE_REVERB_HOST=localhost` since the browser reaches Reverb via the docker-compose port mapping, not the internal service name) to `.env`/`.env.example`; ran `npm install laravel-echo pusher-js` manually; ran `php artisan reverb:install` after (succeeded once env vars existed) to publish `config/reverb.php`. **If a fresh clone ever needs this re-run, expect the same TTY crash in non-interactive/CI shells — do the manual env-var + npm-install fallback above instead of re-running `install:broadcasting`.**
- `rooms` table + `App\Enums\RoomStatus` + `Room` model: room codes are generated in a `static::creating()` hook using a hand-written random-character-picker (NOT `Str::random()`, which has no charset parameter despite looking like it might) over an alphabet that excludes `0/O/1/I` since codes are read aloud/typed on phones. `Room::notExpired()` local scope. Chose **not** to add a `room_participants` table — "who's currently in the room" is inherently presence-channel membership (ephemeral, tracked by Echo/Reverb), and "ready" state before a game starts is similarly transient, so both live only in the `GameLobby` component's server-side state, reconstructed from broadcast events, not a DB table.
- `App\Events\RoomPlayerReadyToggled` and `RoomGameStarting` broadcast on a `PresenceChannel("room.{code}")`; `routes/channels.php` authorizes it by checking the room exists and isn't expired, returning `{id, name, is_host}` (the presence-channel member payload).
- `GameLobby` (`resources/views/components/⚡game-lobby.blade.php`) is a plain **embeddable** Livewire SFC (not a full-page/`pages::` component) since it's meant to be dropped into each game's own room page (Boggle's `/boggle/room/{code}` etc. in Phase 4) — no such page exists yet, so it's only exercised via `Livewire::test('game-lobby', ['room' => $room])` for now. It listens for presence events via `getListeners()` returning `echo-presence:room.{code},{here,joining,leaving,RoomPlayerReadyToggled,RoomGameStarting}` — Livewire wires these to Echo automatically (no manual JS needed) as long as `window.Echo` exists, which `resources/js/echo.js` now provides.
- Found and fixed a real bug while writing `GameLobbyTest`: the "Start game" button's `@disabled(! $this->allReady)` is cosmetic HTML only — the server-side `startGame()` action didn't actually check `allReady` before flipping the room to `Active` and broadcasting `RoomGameStarting`, so a host could start an unready game by calling the action directly (or via devtools). Fixed by adding `abort_unless($this->allReady, 403)` alongside the existing host check.
- Test-writing gotcha worth remembering: `Event::fake()` also fakes **Eloquent model lifecycle events**, so any test that calls `Event::fake()` before `Model::factory()->create()` on a model with a `static::creating()` hook (like `Room`'s code generator) will silently skip that hook and hit NOT NULL constraint errors. Fix is to create fixtures first, then `Event::fake()` only around the action under test.
- **2026-07-29** — Task 8: `type`/`mode`/`result` are plain `string` DB columns backed by PHP enums (`App\Enums\GameType`, `GameMode`, `GameResult`, all string-backed, TitleCase cases per `CLAUDE.md`'s PHP rules) cast via Eloquent's `casts()`, rather than native MySQL `enum` columns — easier to extend later (new game types, e.g.) without an `ALTER TABLE`, and idiomatic for this Laravel version. `game_participants.user_id` is `nullable()->nullOnDelete()` so deleting a `User` preserves game history instead of cascading. Also fixed the same class of bug as the `User.is_public` one preemptively: `GameSession`/`GameParticipant` factories set every column explicitly rather than relying on DB-side defaults Eloquent wouldn't see until a fresh reload (see `rules/migrations.md`'s "mirror defaults in model `$attributes`" guidance — applied via factory defaults here since these two columns don't need model-level defaults for handwritten `create()` calls). Added `tests/Feature/Models/{GameSessionTest,GameParticipantTest}.php`.
- **2026-07-29** — Task 7: added `username`/`is_public` columns (migration `add_profile_fields_to_users_table`), added both to `User`'s `#[Fillable]` list and cast `is_public` to `boolean`. Built the profile editor as a Livewire v4 **full-page single-file component** at `resources/views/pages/⚡profile.blade.php`, routed via `Route::livewire('/profile', 'pages::profile')` (the ⚡ prefix and `pages::` namespace are Livewire v4 defaults — no existing convention to follow since this is the first Livewire component in the app). It also lists/deletes the user's passkeys (via `$user->passkeys()`) and exposes an "Add a passkey" button wired to the same `@laravel/passkeys` client used on the auth pages. Found and fixed a real bug along the way: `UserFactory` didn't set `is_public` explicitly, so a freshly-created-but-not-reloaded `User` instance had `is_public === null` in memory (relies on a DB-side default that Eloquent doesn't pull back after insert) — assigning that to the Livewire component's `bool $is_public` property threw a `TypeError`. Fixed by setting `is_public => true` explicitly in the factory's `definition()`. Real request/response cycles are unaffected since `Auth::user()` always re-reads from the DB on the next request. Added `tests/Feature/ProfileTest.php` covering render, update, username-uniqueness validation, and passkey deletion.
