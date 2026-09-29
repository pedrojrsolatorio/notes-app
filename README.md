# Notes App

A personal notes app built on the Laravel Livewire starter kit. Users organize notes into folders, search across titles and content, and manage their account (password, two-factor authentication, passkeys) — all with a reactive UI and no custom JavaScript for core flows.

## Features

- **Authentication** — email registration/login plus "Continue with Google" (Socialite OAuth), email verification, password reset.
- **Google account linking** — logins match by `google_id`, falling back to email; existing accounts get linked automatically, no duplicates.
- **Folders** — every new user gets seeded defaults (Work, School, Personal, Projects) via `UserObserver`.
- **Notes** — create, select, edit, and assign notes to folders in a single Livewire component (`resources/views/components/notes/⚡index.blade.php`).
- **Live search** — debounced as-you-type search across note titles and content (`wire:model.live.debounce.300ms`), with the OR-grouped `LIKE` query scoped per user.
- **Account settings** — profile, password, 2FA (TOTP + recovery codes), and passkey management.
- **UI** — Flux components with Tailwind styling, dark-mode ready.

## Tech Stack

- **Laravel 13 / PHP 8.3** — backend framework.
- **Livewire 4** — server-side reactivity via single-file page components (`⚡`) registered with `Route::livewire()`.
- **Flux 2 + Tailwind + Alpine.js** — UI components, styling utilities, and client-side interactivity respectively.
- **Fortify (headless)** — auth logic (routes, 2FA, passkeys, rate limiting); Livewire provides the views.
- **Socialite** — Google OAuth driver.
- **Pest + Mockery** — tests (in-memory SQLite); **Pint** — code style; **Larastan** — static analysis.

## Architecture Notes

The codebase splits responsibilities deliberately:

| Layer   | Owns                                              |
|---------|---------------------------------------------------|
| Livewire | UI behavior — state, actions, reactivity (`wire:`) |
| Fortify  | Auth logic — hashing, tokens, 2FA, throttling      |
| Flux     | Appearance — inputs, buttons, modals, toasts       |

Other non-obvious decisions:

- `users.google_id` (nullable, unique) is mass-assignable (`#[Fillable]`) so the Google callback can persist it, and serialized-hidden (`#[Hidden]`) for privacy hygiene.
- `UserObserver` is registered via `User::observe()` in `AppServiceProvider::boot()` — do not also add `#[ObservedBy]` to the model (double registration would seed duplicate folders).
- Eloquent silently discards non-fillable attributes by default (no exception unless strict mode is enabled) — see `GoogleController` history.

## Getting Started

Requirements: PHP 8.3, Composer, Node.js.

```bash
composer setup      # install deps, app key, migrate, frontend build
composer run dev    # start the dev server
```

Google OAuth needs credentials in `.env` (see `config/services.php`):

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
```

## Running the App

```bash
composer run dev
```

This starts everything concurrently — open the app at **http://localhost:8000**:

| Process | Command                | Purpose                              |
|---------|------------------------|--------------------------------------|
| server  | `php artisan serve`    | Serves the app                       |
| queue   | `php artisan queue:listen` | Processes queued jobs (DB queue) |
| logs    | `php artisan pail`     | Tails logs in a tab                  |
| vite    | `npm run dev`          | Frontend hot-reload (HMR)            |

Notes:

- **Manual alternative:** run `php artisan serve` and `npm run dev` in separate terminals.
- **Frontend changes not showing?** Restart the Vite process, or run `npm run build` for a production bundle (required before `serve`-only runs pick up new assets).
- **Flags:** `php artisan dev --stream` (single merged log) or `--inline`; `php artisan dev:list` shows the registered processes.
- **Production-like preview:** `npm run build`, then `php artisan serve` (no Vite needed).

## Testing

```bash
php artisan test --compact              # full suite
vendor/bin/pest tests/Feature/Auth/GoogleControllerTest.php  # single file
vendor/bin/pint --dirty --format agent  # code style (run after PHP changes)
```

Tests run against an in-memory SQLite database (`phpunit.xml`) with `RefreshDatabase` (`tests/Pest.php`), so no manual cleanup is ever needed. Key suites: `tests/Feature/Auth/GoogleControllerTest.php` (Google create/link flows), plus the starter kit's registration/authentication/settings tests.

## Project Structure

```text
app/
  Http/Controllers/Auth/GoogleController.php  # Google OAuth redirect + callback
  Livewire/Actions/Logout.php                 # reusable logout action
  Models/ (User, Folder, Note)                # Eloquent models + relations
  Observers/UserObserver.php                  # seeds default folders on registration
resources/views/
  components/notes/⚡index.blade.php          # notes UI (folders, list, editor, search)
  pages/auth/                                 # login, register, 2FA, password pages
  pages/settings/⚡*.blade.php                # profile, security, appearance pages
routes/
  web.php                                     # home, dashboard, Google OAuth routes
  settings.php                                # Route::livewire() settings pages
tests/Feature/Auth/                           # auth flow tests
```
