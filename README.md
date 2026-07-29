# Low Stim Play

A small collection of browser games — Boggle, Yahtzee, Family Feud — designed to be gentle on the eyes: low contrast, no flashing or motion, calm OKLCH color palettes. Good for anyone prone to migraines, vertigo, epilepsy, or general photosensitivity.

This repository is a Laravel rewrite of an earlier static HTML/CSS/JS version of these games (preserved in [`legacy/`](legacy/)). The rewrite adds real accounts, passkey login, game history, and live multiplayer, while keeping the same low-stimulation design constraints.

## Status: migrated, including front-end fidelity

The Laravel rewrite is functional end-to-end and now matches the legacy site's actual look and feel, not just its game logic — every phase of [`docs/plan.md`](docs/plan.md) is built and tested (170+ tests), and a follow-up pass (Phase 8 in [`docs/progress.md`](docs/progress.md)) ported the real CSS design system, the real Boggle drag/tap word-building gesture, the two previously-missing pages (group-play Boggle, dice-only Yahtzee), and a consistent button-bar/site-footer/rules-dialog on every page. See `docs/progress.md` for what was built, what was deliberately descoped (and why), and real bugs found along the way — including two front-end bugs (a Tailwind theme-token config issue and a CSS cascade-layer ordering issue) that were silently breaking accent colors and sizing across the whole site.

## What's here

- **Boggle** — solo play, a shared-screen group-play variant (no word entry, just a board + timer), online multiplayer rooms (shared board via Reverb), and async challenge links, ported from `legacy/boggle.html` and `legacy/boggle-static.html`
- **Yahtzee** — full scorecard (pass-and-play for 1–8 players on one device) and a dice-only roller with no scorecard, ported from `legacy/yahtzee.html` and `legacy/yahtzee-roller.html`
- **Family Feud** — single shared screen, an LLM (via OpenRouter) judges guesses server-side so there's no hidden host view to manage, ported from `legacy/family-feud.html`
- Accounts via Fortify + passkeys (WebAuthn), password login as a fallback; public profiles and a stats dashboard aggregated from game history
- The same design language as the legacy site: OKLCH color tokens, `clamp()`-based fluid sizing, no animations/transitions, muted contrast, a real button-bar/site-footer/rules-dialog on every page — ported as actual CSS from `legacy/shared.css`/`legacy/boggle-shared.css`, not a utility-class reinterpretation

Not built (see `docs/progress.md` for why): friends/social features on the dashboard, image avatars, online multiplayer for Yahtzee/Family Feud (both are pass-and-play only; the `Room`/`GameLobby` infrastructure Boggle's online rooms use is there to reuse if that's ever wanted), and a couple of legacy's JS-measured pixel-perfect refinements (dynamic found-word column splitting, exact footer-height measurement) in favor of their CSS fallback equivalents.

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
