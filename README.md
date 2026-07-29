# Low Stim Play

A small collection of browser games — Boggle, Yahtzee, Family Feud — designed to be gentle on the eyes: low contrast, no flashing or motion, calm OKLCH color palettes. Good for anyone prone to migraines, vertigo, epilepsy, or general photosensitivity.

This repository is a Laravel rewrite of an earlier static HTML/CSS/JS version of these games (preserved in [`legacy/`](legacy/)). The rewrite adds real accounts, passkey login, game history, and live multiplayer, while keeping the same low-stimulation design constraints.

## Status: migration complete

The Laravel rewrite is functional end-to-end — every phase of [`docs/plan.md`](docs/plan.md) is built and tested (170+ tests). See [`docs/progress.md`](docs/progress.md) for what was built, what was deliberately descoped from the plan's literal wording (and why), and real bugs found along the way.

## What's here

- **Boggle** — solo play, online multiplayer rooms (shared board via Reverb), and async challenge links, ported from `legacy/boggle.html`
- **Yahtzee** — full scorecard, pass-and-play for 1–8 players on one device, ported from `legacy/yahtzee.html`
- **Family Feud** — single shared screen, an LLM (via OpenRouter) judges guesses server-side so there's no hidden host view to manage, ported from `legacy/family-feud.html`
- Accounts via Fortify + passkeys (WebAuthn), password login as a fallback; public profiles and a stats dashboard aggregated from game history
- The same design language as the legacy site: OKLCH color tokens, no animations/transitions, muted contrast — see `docs/plan.md` §1.4

Not built (see `docs/progress.md` for why): friends/social features on the dashboard, image avatars, and online multiplayer for Yahtzee/Family Feud (both are pass-and-play only; the `Room`/`GameLobby` infrastructure Boggle's online rooms use is there to reuse if that's ever wanted).

## Local development

The project runs via a custom `docker-compose.yml` (not Sail): `app` (PHP-FPM), `nginx`, `mariadb`, `redis`, plus `queue` and `reverb` worker services.

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
docker compose exec app npm install
docker compose exec app npm run build
```

Then visit `http://localhost`.

Family Feud's LLM judge needs a real `OPENROUTER_API_KEY` set in `.env` (get one at [openrouter.ai](https://openrouter.ai)) — without it, Boggle and Yahtzee work fine, but every Family Feud guess will show "Couldn't reach the judge."

Run tests:

```bash
docker compose exec app php artisan test --compact
```

## Legacy version

The original static, no-build version of these games still lives in [`legacy/`](legacy/) and its `README.md` — no server, no accounts, just open an HTML file in a browser. It remains the reference implementation for game rules and the design language during the rewrite.
