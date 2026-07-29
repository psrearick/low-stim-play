# Low Stim Play

A small collection of browser games — Boggle, Yahtzee, Family Feud — designed to be gentle on the eyes: low contrast, no flashing or motion, calm OKLCH color palettes. Good for anyone prone to migraines, vertigo, epilepsy, or general photosensitivity.

This repository is a Laravel rewrite of an earlier static HTML/CSS/JS version of these games (preserved in [`legacy/`](legacy/)). The rewrite adds real accounts, passkey login, game history, and live multiplayer, while keeping the same low-stimulation design constraints.

## Status: early migration in progress

This is **not yet functional** — the application is a fresh Laravel skeleton being built out phase by phase. See [`docs/plan.md`](docs/plan.md) for the full migration plan and [`docs/progress.md`](docs/progress.md) for current status against that plan.

Nothing here is deployed or usable yet. If you're picking this project back up, start with `docs/progress.md`.

## What's being built

- **Boggle, Yahtzee, Family Feud** as Livewire games, ported from the legacy static pages (`legacy/boggle.html`, `legacy/yahtzee.html`, `legacy/family-feud.html`)
- Accounts via Fortify + passkeys (WebAuthn), password login as a fallback
- Solo, local pass-and-play, real-time online multiplayer (Laravel Reverb), and async challenge modes
- Game history and per-user stats
- The same design language as the legacy site: OKLCH color tokens, no animations/transitions, muted contrast — see `docs/plan.md` §1.4

## Local development

The project runs via a custom `docker-compose.yml` (not Sail): `app` (PHP-FPM), `nginx`, `mariadb`, `redis`, plus `queue` and `reverb` worker services.

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan migrate
docker compose exec app npm install
docker compose exec app npm run build
```

Then visit `http://localhost`.

Run tests:

```bash
docker compose exec app php artisan test --compact
```

## Legacy version

The original static, no-build version of these games still lives in [`legacy/`](legacy/) and its `README.md` — no server, no accounts, just open an HTML file in a browser. It remains the reference implementation for game rules and the design language during the rewrite.
