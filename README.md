# Low Stim Play

A small collection of browser games — Boggle, Yahtzee, Family Feud, Farkle ("10,000"), Best Match, and Clear the Deck (Tens) — designed to be gentle on the eyes: low, consistent contrast, no flashing or motion, and a calm OKLCH-based color palette. Built for anyone prone to migraines, vertigo, epilepsy, or general photosensitivity.

Accounts use passkeys (WebAuthn) with password login as a fallback. Every game supports pass-and-play on one device, and most also support real-time online multiplayer via rooms — a persistent, shareable code a group can join, switch games within, and reconnect to at any point.

## Games

| Game                                | Modes                                                                 | Notes                                                              |
|-------------------------------------|-----------------------------------------------------------------------|--------------------------------------------------------------------|
| **Boggle** ("Word Grid")            | Solo, group-play (shared screen), online rooms, async challenge links | 4×4/5×5 board, drag/tap word building, ~79k-word dictionary solver |
| **Yahtzee** ("Yacht")               | Pass-and-play (1–8 players), online rooms, dice-only roller           | Full scorecard with upper-section bonus and joker rules            |
| **Family Feud** ("Survey Says")     | Pass-and-play, online rooms (teams + captain-gated steal)             | An LLM judges guesses server-side, so there's no hidden host view  |
| **Farkle** ("10,000")               | Pass-and-play, online rooms                                           | Standard scoring, hot dice, configurable target score              |
| **Apples to Apples** ("Best Match") | Online rooms only                                                     | An "Apples to Apples"-style card-matching game                     |
| **Clear the Deck (Tens)**           | Online rooms only                                                     | A turn-based shedding card game with wild Tens                     |

Each game's setup screen also links into any room you're currently hosting, and a navbar "Rooms" menu lets you create or join a room from anywhere in the app.

## Design principles

- **Low, consistent contrast** — every color is defined in [OKLCH](https://oklch.com/), a perceptually uniform color space, so foreground/background relationships stay predictable and muted instead of jarring.
- **No animation, no transitions** — nothing flashes, slides, or pulses. `prefers-reduced-motion` is respected everywhere it would matter anyway.
- **No native browser dialogs** — confirmations use the app's own low-stim `<dialog>` components (`<x-dialog>`/`<x-confirm-dialog>`) instead of `window.confirm()`/`window.prompt()`, which render as a jarring OS-styled modal outside the app's own design system.
- **Calm, muted palette** — dark, low-saturation backgrounds with soft accent colors rather than pure black/white or saturated brights, with a light theme available too.

## Tech stack

- **Backend**: Laravel 13 (PHP 8.5), Livewire 4 (single-file `pages::`/`⚡` components), Laravel Fortify + passkeys for auth, Laravel Reverb for WebSocket broadcasting
- **Frontend**: Blade, Tailwind CSS v4, vanilla JS/Alpine (bundled with Livewire) for client-side interaction (Boggle's drag gesture, dialogs, the navbar)
- **Data**: MariaDB, Redis (sessions, cache, queues)
- **Testing**: Pest v5, including real-browser tests via `pestphp/pest-plugin-browser` (Playwright)

## Project structure

```
app/
  Actions/Fortify/     Fortify auth actions (registration, password reset, etc.)
  Analytics/           Self-contained first-party analytics system (see below)
  Console/Commands/    Scheduled/manual artisan commands (guest pruning, GeoIP updates, ...)
  Enums/               GameType, GameMode, GameResult, RoomStatus, YahtzeeCategory, ...
  Events/              Broadcast events (room/game state changes)
  Http/                Controllers, form requests, middleware
  Models/              Eloquent models (User, Room, RoomSeat, RoomMember, GameSession, GameParticipant, ...)
  Services/            Per-game engines (Boggle, Yahtzee, Farkle, FamilyFeud) and stats services
resources/
  css/app.css          The full design-token/component system, ported from the original static CSS
  js/                  Echo setup, delegated dialog/sidebar JS, passkey ceremonies
  views/
    pages/              Livewire full-page components (⚡name.blade.php), routed via Route::livewire()
    components/         Blade/Livewire components (game-lobby, dialogs, sidebar, stat cards, ...)
routes/
  web.php               All page routes
  channels.php          Reverb presence-channel authorization
database/
  migrations/, factories/, seeders/
docs/
  DEVLOG.md, CHANGELOG.md, TODO.md, specs/   See "Documentation" below
tests/
  Unit/, Feature/, Browser/                  See "Testing" below
```

Rooms are the shared multiplayer primitive: a `Room` has member (`RoomMember`) and per-game seat (`RoomSeat`) tracking, a `GameSession` holding the current game's persisted state, and broadcast events that keep every connected client in sync. Any game can be built against this without its own bespoke multiplayer plumbing.

## Local development

The project runs via a custom `docker-compose.yml` (not Sail): `app` (PHP-FPM), `nginx`, `mariadb`, `redis`, plus `queue` and `reverb` worker services.

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
docker compose exec app npm install
docker compose exec app npm run build
```

Then visit `http://localhost`.

For active frontend work, `npm run dev` (inside the container, or on the host if you have Node installed locally) starts Vite in watch mode instead of a one-off build.

### Environment variables

`.env.example` documents every variable this app reads; most have working local defaults already filled in. A few need a real value to unlock specific features:

- **`OPENROUTER_API_KEY`** — Family Feud's LLM judge (get one at [openrouter.ai](https://openrouter.ai)). Without it, every other game works fine, but Family Feud guesses will show "Couldn't reach the judge."
- **`RESEND_API_KEY`** — outbound mail (verification emails, etc.) in production. Locally, `MAIL_MAILER=log` writes emails to `storage/logs/laravel.log` instead of sending them, so this isn't needed for local dev.
- **`MAXMIND_ACCOUNT_ID`/`MAXMIND_LICENSE_KEY`** — country resolution for the analytics dashboard's GeoIP lookups (`analytics:geoip-update`). Leaving these blank just means the countries panel stays empty.
- **`ANALYTICS_ADMIN_EMAILS`** — comma-separated allow-list gating the `/analytics` dashboard; there's no `is_admin` column, so this is the entire access-control story for that page.

### Reverb / broadcasting

Real-time features (rooms, online multiplayer) depend on Laravel Reverb. If you ever need to re-run `php artisan install:broadcasting --reverb` on a fresh clone, it can crash in a non-interactive shell (no TTY for its npm step). If that happens, do it by hand instead: set the `REVERB_*`/`VITE_REVERB_*` variables in `.env` (already filled in in `.env.example`), run `npm install laravel-echo pusher-js`, then `php artisan reverb:install`.

## Testing

```bash
docker compose exec app php artisan test --compact
```

The suite is organized into three tiers:

- **`tests/Unit`** — pure logic (scoring engines, dice rollers, stats services) with no framework bootstrapping.
- **`tests/Feature`** — HTTP/Livewire-level tests (`Livewire::test()`, `$this->get(...)`) covering routes, authorization, and component state.
- **`tests/Browser`** — real, Playwright-backed browser tests (`visit(...)`), for anything Feature tests can't see: CSS-driven visibility, `<dialog>` behavior, drag gestures, responsive/mobile layout, and other real-DOM behavior. See `CLAUDE.md`/`AGENTS.md` for the conventions and known gotchas around this suite (e.g. `resize()` vs. `on()->mobile()` for anything that clicks).

There's also a `vendor/bin/pest --agent='...'` probe for one-off manual verification while working (not a substitute for real test coverage) — see `CLAUDE.md`/`AGENTS.md` for details.

Static analysis and formatting:

```bash
composer analyse   # Larastan (PHPStan), level 5
composer format     # Laravel Pint
composer check       # both
```

## Deployment

The app is set up to deploy to [Laravel Cloud](https://cloud.laravel.com/). A few things are deployment-specific and worth checking before/after a deploy (see `docs/DEVLOG.md`'s "Analytics" section for the full detail):

- `SESSION_LIFETIME`, `ANALYTICS_ADMIN_EMAILS`, and the Reverb/Redis/mail credentials all need to be set as real environment variables on the deployment target — `.env` is gitignored and only documents intended local values.
- `bootstrap/app.php` trusts all proxies (`trustProxies(at: '*')`) so the analytics pipeline resolves a real client IP rather than a load balancer's own address; `analytics:doctor <url>` can verify this against a live deployment.
- Laravel Cloud's filesystem is ephemeral, so the scheduled `analytics:geoip-update` command needs to actually run (or the `.mmdb` file needs to live in durable storage) for the analytics countries panel to stay populated after a fresh deploy.

## Documentation

This project keeps four living docs in `docs/`, each with a distinct job:

- **[`docs/DEVLOG.md`](docs/DEVLOG.md)** — the detailed record of *why* the app is built the way it is: decisions, deliberate deviations from any plan, and bugs found along the way (including root causes, not just fixes). Organized by feature/area, not by date.
- **[`docs/CHANGELOG.md`](docs/CHANGELOG.md)** — a terse, commit-by-commit record of *what* changed, newest first, with no rationale (that's in the DEVLOG).
- **[`docs/TODO.md`](docs/TODO.md)** — incomplete or deferred work only, each item with a permanent ID. Once something's done, its writeup moves into the DEVLOG and is deleted here.
- **`docs/specs/`** — per-feature build specs (e.g. `farkle.md`, `analytics.md`), written before a substantial feature is built when open design questions need answering first.

The general workflow: a new feature or fix gets planned (a spec, for anything with open design questions), built, and then documented in the same pass — a DEVLOG entry under the relevant section, and a CHANGELOG line for the commit. `docs/notes.md` and `docs/concerns.md` are the user's own scratch inboxes for unprocessed thoughts and bug reports, periodically triaged into `TODO.md` or fixed directly.
