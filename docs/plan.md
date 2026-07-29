# Low-Stim Play — Migration & Extension Plan

## Context

The `legacy/` directory contains a static HTML/CSS/JS game suite built for photosensitive and migraine-prone users (Boggle, Yahtzee, Family Feud). The goal is to migrate this into a proper Laravel application with Livewire components, user accounts with passkey authentication, game history/stats, and real-time multiplayer via Laravel Reverb.

**Key decisions:**
- Custom `docker-compose.yml` (not Sail) with mariadb + redis
- Fortify + `laravel/passkeys` for auth (no Breeze; custom Blade/Livewire views)
- Boggle migrated first to validate the architecture
- Tailwind v4 for styling

---

## Phase 1: Infrastructure & Tooling

### 1.1 Custom Docker Compose

Create `docker-compose.yml` at the project root:
- **`app`** service: PHP 8.5-fpm, mounts project root, exposes nothing (behind nginx)
- **`nginx`** service: port 80 → app:9000, serves `public/`
- **`mariadb`** service: latest MariaDB, persistent volume
- **`redis`** service: Alpine Redis, persistent volume
- `docker/nginx/default.conf` — standard Laravel nginx config
- `docker/php/Dockerfile` — PHP 8.5-fpm with required extensions (pdo_mysql, redis, pcntl, etc.)

Update `.env`:
- `DB_HOST=mariadb`, `DB_DATABASE=low_stim_play`, `DB_USERNAME=low_stim`, `DB_PASSWORD=secret`
- `REDIS_HOST=redis`
- `BROADCAST_CONNECTION=reverb`
- `SESSION_DRIVER=redis`
- `CACHE_STORE=redis`
- `QUEUE_CONNECTION=redis`
- `OPENROUTER_API_KEY=` (for Family Feud judging later)

### 1.2 Packages to Install

```bash
# Auth
composer require laravel/fortify
composer require laravel/passkeys

# UI & Realtime
composer require livewire/livewire
composer require laravel/reverb

# Static analysis
composer require --dev larastan/larastan

# Already installed: pint, pest, pest-plugin-laravel
```

NPM additions:
```bash
npm install @laravel/passkeys alpinejs
```

### 1.3 Static Analysis & Linting

- **Pint** (already installed) — formatting; PhpStorm's Pint integration uses the same `vendor/bin/pint` binary
- **Larastan** — `phpstan.neon` at project root, level 5 to start
- **Pre-commit hook** (`.git/hooks/pre-commit`): runs `vendor/bin/pint --dirty` then `vendor/bin/phpstan analyse --memory-limit=512M`
- Add composer scripts: `"format": "pint"`, `"analyse": "phpstan analyse"`, `"check": ["@format", "@analyse"]`

### 1.4 Design System

Port the legacy design language into Tailwind v4 CSS custom properties in `resources/css/app.css`:
- OKLCH color tokens: `--color-bg`, `--color-surface`, `--color-text`, `--color-accent`, `--color-warn`
- Dark/light mode via Tailwind's `dark:` class strategy
- Global rule: `*, *::before, *::after { animation: none !important; transition: none !important; }`
- Font: same Instrument Sans (via Bunny CDN, already in Vite config)

---

## Phase 2: Authentication & User Profiles

### 2.1 Fortify Setup

```bash
php artisan fortify:install
```

In `FortifyServiceProvider`:
- Enable features: `registration`, `resetPasswords`, `emailVerification`, `passkeys`
- Wire up view callbacks: `Fortify::loginView(fn() => view('auth.login'))`, etc.
- All views built as simple custom Blade/Livewire — no Breeze templates

### 2.2 Passkeys

```bash
php artisan passkeys:install
php artisan migrate
```

- Add `PasskeyAuthenticatable` trait + `PasskeyUser` contract to `User` model
- Configure `config/passkeys.php`: `relying_party_id`, `origins`
- `@laravel/passkeys` npm package handles browser-side WebAuthn ceremony
- Auth views offer both passkey button and password fallback

### 2.3 Profile Extension

New migration: `add_profile_fields_to_users_table`
- `username` — string, unique, nullable initially (set on first login)
- `is_public` — bool, default true

**Auth views (custom Blade, no Breeze):**
- `resources/views/auth/login.blade.php` — passkey + password fallback
- `resources/views/auth/register.blade.php` — email + password
- `resources/views/auth/profile.blade.php` — Livewire component for profile editing

---

## Phase 3: Database Models for Game Tracking

### `game_sessions` table
| column | type | notes |
|--------|------|-------|
| id | bigint PK | |
| type | enum | `boggle`, `yahtzee`, `family_feud` |
| mode | enum | `solo`, `local`, `online`, `async` |
| room_code | string(6) nullable | online multiplayer rooms |
| settings | json | board size, time limit, round count, etc. |
| started_at | timestamp | |
| ended_at | timestamp nullable | |

### `game_participants` table
| column | type | notes |
|--------|------|-------|
| id | bigint PK | |
| game_session_id | FK | |
| user_id | FK nullable | null = guest |
| guest_name | string nullable | |
| score | int | |
| result | enum | `win`, `loss`, `draw` |
| stats | json | per-game specifics (words found, categories scored, etc.) |

Models: `GameSession`, `GameParticipant` with factories and seeders.

---

## Phase 4: Boggle (First Game Migration)

### 4.1 PHP Service Classes

Extract all logic from `legacy/boggle-shared.js` to PHP:

- **`app/Services/Boggle/BoardGenerator.php`** — dice sets, Fisher-Yates shuffle, grid layout; 4×4 and 5×5 support
- **`app/Services/Boggle/Solver.php`** — build trie from `storage/app/words.txt`, DFS with backtracking over adjacency graph; handles "Qu" die face
- **`app/Services/Boggle/Scorer.php`** — word length → point value (1/2/3/5/11)

Words file: copy `legacy/words.txt` → `storage/app/words.txt`. Load as Trie once per request (or cache in Redis between requests).

### 4.2 Livewire Components

- **`BoggleSetup`** — board size + time limit picker; emits to parent
- **`BoggleGame`** — main game; letter selection via Alpine.js drag/tap, found words list, real-time score, countdown timer (Alpine.js `$interval`)
- **`BoggleReveal`** — post-game screen; shows all valid words with found ones highlighted (server computes via `Solver`)

### 4.3 Routes

```
GET  /boggle              → BoggleLobby (solo or join)
GET  /boggle/play         → BoggleGame (solo)
GET  /boggle/room/{code}  → BoggleGame (multiplayer room)
GET  /boggle/share/{hash} → BoggleGame (async challenge — same board, play any time)
```

### 4.4 Multiplayer via Reverb (online mode)

- Players join a room (6-char code); host generates board seed, server broadcasts seed to all
- Each player plays on their own device with the same board
- Timer synchronized via broadcast event from server (host triggers start)
- At timer expiry: server collects scores, determines winner, records `GameSession`
- Broadcast events: `BoggleGameStarted`, `BoggleTimerExpired`

### 4.5 Async Challenge Mode

- Host plays solo; at end, a shareable URL is generated with a board hash
- Recipient loads same board (same seed), plays solo at their own pace
- Results page compares scores side by side

### 4.6 Tests

```
tests/Unit/Boggle/BoardGeneratorTest.php  — board dimensions, dice constraints, Qu handling
tests/Unit/Boggle/SolverTest.php          — known words found, DFS adjacency, min length
tests/Unit/Boggle/ScorerTest.php          — point values per word length
tests/Feature/Games/BoggleGameTest.php   — Livewire component interactions, game recording
tests/Feature/Games/BoggleRoomTest.php   — room join, Reverb events
```

---

## Phase 5: Room System (Shared Infrastructure)

Built alongside Boggle, reused for all subsequent games.

### `rooms` table
| column | type |
|--------|------|
| id | bigint PK |
| code | string(6) unique |
| game_session_id | FK nullable |
| host_user_id | FK |
| status | enum: `lobby`, `active`, `finished` |
| max_players | int |
| expires_at | timestamp |

**Livewire `GameLobby` component** — real-time player list (Reverb presence channel `room.{code}`), ready states, config panel (host-only), start button.

---

## Phase 6: Subsequent Games (after Boggle validates the architecture)

**Yahtzee:**
- `app/Services/Yahtzee/DiceRoller.php` (server-side random), `Scorer.php`, `GameEngine.php`
- Livewire `YahtzeeGame` — pass-and-play first (no Reverb needed), then online

**Family Feud:**
- Questions → `family_feud_questions` table + seeder (from `legacy/netlify/functions/questions.json`)
- `app/Services/FamilyFeud/JudgeService.php` — OpenRouter HTTP client
- `app/Jobs/JudgeFamilyFeudAnswer` — queued job (async so UI doesn't block)
- Livewire `FamilyFeudHost` and `FamilyFeudDisplay` (TV mode) connected via Reverb

---

## Phase 7: User Profiles & Stats

- `GET /profile/{username}` — public profile: avatar, recent games, win/loss, per-game stats
- `GET /dashboard` — authenticated user's own stats, friends activity
- Stats aggregated from `game_participants` (score avg, games played, win rate per game type)

---

## Implementation Order

1. Custom `docker-compose.yml` + nginx config + PHP Dockerfile
2. `.env` updates (mariadb, redis, reverb keys)
3. Install packages (Fortify, passkeys, Livewire, Reverb, Larastan)
4. Tooling: `phpstan.neon`, pre-commit hook, composer scripts
5. Design system (CSS tokens, dark mode, no-animation rule)
6. Auth: Fortify + passkeys config, custom auth views
7. Profile extension migration + profile view
8. Game tracking models (`GameSession`, `GameParticipant`, factories)
9. Room system (`rooms` table, `GameLobby` component)
10. **Boggle:** service classes, Livewire components, solo → multiplayer → async
11. Boggle tests (unit + feature)
12. Yahtzee: services + Livewire
13. Family Feud: question seeder, judge service, host/display components
14. User profile & stats pages

---

## Verification

- `./vendor/bin/pest --compact` — all tests pass
- `./vendor/bin/phpstan analyse` — no errors at level 5
- `./vendor/bin/pint --test` — no formatting violations
- `docker compose up -d` → `http://localhost` loads the app
- Register + set up a passkey → log out → log in with passkey
- Play a solo Boggle game → verify `game_sessions` + `game_participants` records created
- Open two browsers in same Boggle room → verify shared board via Reverb
