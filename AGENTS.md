<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.3. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>

# Project Instructions — Notes App

Project-specific rules for this repo. The Boost guidelines above are framework-managed; this section is hand-maintained. Verified 2026-10-11 against `composer.lock` / `package-lock.json` and the working tree.

## Verified Stack

- PHP >= 8.3 (CI: `shivammathur/setup-php` 8.3; dev machine: `/usr/bin/php8.3` via `opencode.json`; verified under PHP 8.3.33), Laravel framework v13.32.0, Livewire v4.4.5, Flux v2.20.0, Fortify v1.39.0, Socialite v5.31.0, Pest v4.7.8.
- JS: Tiptap 3.31.3, Vite 8.3.0, Tailwind 4.3.3, Node 22 in CI. `public/build` manifest exists — frontend is built.
- Tests: in-memory SQLite (`phpunit.xml`), `RefreshDatabase` for `tests/Feature` (`tests/Pest.php`). No test cleanup needed.
- Full suite status: **37 passed / 94 assertions** (verified 2026-10-11). PHPStan level 7 configured (`phpstan.neon`, paths: app, bootstrap/app.php, config, database, routes) — **could not be verified in sandbox (timed out); run `composer types:check` on the dev machine.**

## Run & Verify

- First time: `composer setup`. Daily loop: `composer run dev` (concurrently: `serve` + `queue:listen` + `pail` + `npm run dev`) → http://localhost:8000. See `README.md` "Running the App".
- Tests: `php artisan test --compact`, single file: `vendor/bin/pest <path>`.
- Style: `vendor/bin/pint --dirty --format agent` after PHP changes. (Git object damage found 2026-10-11 was repaired via GitHub object transplant — see `docs/PROJECT_STATUS.md`. If git acts up again, pass explicit file paths instead of `--dirty`.)
- Sandbox note: this agent environment has no local PHP. Verified commands via `php:8.3-cli` container with `-e VIEW_COMPILED_PATH=/tmp/vc` (the sandbox mount cannot read `storage/framework/views/livewire/*`; on the dev machine this workaround is unnecessary).

## Architecture Map

- `routes/web.php` — home, dashboard, Google OAuth, `Route::livewire('/notes', 'notes.index')`, `note-attachments/{attachment}/download` (all behind `auth`+`verified`).
- `app/Models/` — `User` (Fortify/2FA/passkey traits, `#[Fillable]` incl. `google_id`, `#[Hidden]` incl. secrets + `google_id`), `Folder`, `Note` (SoftDeletes, `version` column currently unused), `NoteAttachment`.
- `app/Observers/UserObserver.php` — seeds Work/School/Personal/Projects on user `created`. Registered **once** via `User::observe()` in `AppServiceProvider::boot()`; never add `#[ObservedBy]` (double registration = duplicate folders).
- `app/Http/Controllers/Auth/GoogleController.php` — matches by `google_id`, falls back to email, links + verifies.
- `app/Http/Controllers/NoteAttachmentController.php` — owner-checked download (403/404).
- `resources/views/components/notes/⚡index.blade.php` — the notes UI (folders, search/sort, Tiptap editor, attachments form). `resources/js/tiptap.js` + 4-line `app.js` expose `window.createTiptapComponent`.
- `database/migrations/` — users + `google_id`, folders (`unique(user_id,name)`, cascade on user delete), notes (`nullOnDelete` folder, cascade on user delete, cascade note→attachments via attachments migration).

## Conventions (follow what's here)

- Livewire 4 single-file `⚡` components; full pages via `Route::livewire()`; nested pieces via `<livewire:.../>`. Pair Flux components with `wire:` bindings (`flux:input wire:model=...`).
- Pest `test()` style, files grouped by flow (`tests/Feature/Auth/`, `tests/Feature/NotesTest.php`). Read `testing-best-practices` skill before writing tests; fakes/mocks created inside each test.
- Attribute-style model config (`#[Fillable]`, `#[Hidden]`); do NOT import classes from the current namespace (Pint's `no_unused_imports` removes them — verified); Pint `laravel` preset.
- Links via `route()`; Alpine for client-only concerns, Livewire for anything needing the server. Cross-scope Alpine calls use the window-event pattern (see Save fix below) — never call a sibling `x-data` method directly.
- Note and attachment actions report success and validation failures with `Flux::toast()`; rethrow validation exceptions so inline `@error` messages remain visible.

## Authorization & Data Isolation (load-bearing)

- **Scope every note/folder/attachment query to `auth()->user()`** (`$user->notes()`, `$user->folders()`, `findOrFail` on the scoped relation). This is the project's primary access control — there are no policies.
- `saveNote()` validates `folder_id` against the user's own folders (`Rule::exists(...)->where('user_id', ...)`) — a forged cross-user id fails validation instead of leaking note content into a victim's folder. Keep this pattern for any future assignment of user-chosen relations.
- `saveNote()` validates `title` as `required|string|max:255` (`title` is `string(255)`). Validation errors render via `@error` under the inputs — never add a validated rule without its display.
- Search `LIKE` does not escape `%`/`_` (semantics only, not a leak — the OR group is correctly parenthesized).
- `NoteAttachmentController::download` is the reference pattern for owner checks (user match → 403; note ownership + file existence → 404). Reuse it for any new file route (e.g. attachment delete).
- `#[Locked]` = server-only mutable; `#[Computed]` = read-only derived; plain `public` = client-mutable (validate it). Never store auth decisions in plain public props.
- Google OAuth callback has no throttle — acceptable for now (Google-verified emails), note before exposing broadly.

## Storage & Uploads

- Disk `local` (`storage/app/private`), per-user subdirs (`note-attachments/{userId}`). Never move uploads to `public` without an owner-checked route.
- Upload validation lives in `attachmentValidationRules()` (10 MB, explicit mime list, `@error` display). Keep limits in sync with server `upload_max_filesize`/`post_max_size`.
- Missing: attachment **list/download-link/delete UI** (backend complete, template has form only). `render()` does not eager-load attachments — fine for one note; revisit if listing grows.

## Migrations

- Additive-only on existing tables. New tables in this project: folders, notes, `google_id` on users, note_attachments. Deletions cascade user→folders/notes and note→attachments; folder delete nulls `notes.folder_id`.
- `DB::prohibitDestructiveCommands(app()->isProduction())` is on — destructive ops fail in prod by design.

## Frontend / Tiptap Constraints

- Editor DOM is owned by Tiptap: the editor container keeps `wire:ignore` + `wire:key="tiptap-editor-{id}"`. Never put morph-dependent directives (`@error`, `wire:loading`, conditionals) inside it.
- Editor content reaches PHP **only** via the `save-note-requested` window event → `$wire.saveNote(getContent())`. `$content` is seed-on-render, not live-synced.
- Rebuild assets after JS changes (`npm run dev` for HMR, `npm run build` before serve-only runs). `window.createTiptapComponent` must exist or the editor silently never initializes (past Save-button failure mode — check `storage/logs/browser.log` first for Alpine errors).
- Note content is stored as raw HTML: render only via `strip_tags(...)` (list) or `@js(...)` (editor seed). Never `{!! $note->content !!}`. Sole `{!! !!}` in views is Fortify's server-generated QR SVG.

## Working Rules

- Read the file(s) under change plus neighbors before editing; smallest correct change; no unrelated refactors.
- Never invent paths, methods, versions, or test results — verify from the tree (`composer.lock`, `package-lock.json`, route files).
- No destructive commands, DB resets, data-touching migrations, or dependency upgrades without explaining risk + explicit confirmation.
- Never claim success without running the relevant checks (Pest file(s) + Pint on touched PHP). Fix regressions you cause.
- `docs/PROJECT_STATUS.md` is the audited state of the repo — update it when behavior changes (features completed, issues fixed/found).
