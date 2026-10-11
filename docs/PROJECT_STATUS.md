# Project Status — Notes App

Audited 2026-10-11 by direct repository inspection (lock files, migrations, models, routes, components, logs) plus a full test-suite run. Update this file when behavior changes.

## Implemented & Verified

- **Auth (starter kit + Fortify v1.39.0):** registration, login, email verification, password reset/confirm, 2FA with confirm + recovery codes, passkeys. Covered by `tests/Feature/Auth/*` and `tests/Feature/Settings/*`.
- **Google OAuth (Socialite v5.31.0):** `/auth/google/redirect` + `/callback`; matches by `google_id`, falls back to email, links + verifies. Covered by `tests/Feature/Auth/GoogleControllerTest.php`. `google_id` is `#[Fillable]` (mass-assignable) and `#[Hidden]`.
- **Default folders:** `UserObserver::created` seeds Work/School/Personal/Projects; registered once via `User::observe()` in `AppServiceProvider::boot()`. Proven working (a duplicate-name insert fails on `unique(user_id,name)` during tests).
- **Notes (`Route::livewire('/notes', 'notes.index')`):** create/select/edit, folder filter + Trash, soft-delete/restore/force-delete, debounced live search (correctly parenthesized `LIKE` group), 4 sort orders, Tiptap rich-text editor. Covered by `tests/Feature/NotesTest.php` (save paths only).
- **Save-button fix (2026-10-11):** the Save button called `getContent()` outside its Alpine `x-data` scope (`ReferenceError`, see `storage/logs/browser.log`). Fixed with the `save-note-requested` window-event pattern; no layout or server change.
- **Attachments:** validated upload (10 MB, explicit mimes) storing to `local` disk under `note-attachments/{userId}` + `NoteAttachment` row; owner-checked download route/controller (403/404). See "Incomplete" for the UI gap.
- **Frontend built:** `public/build/manifest.json` exists; Vite 8.3.0, Tailwind 4.3.3, Tiptap 3.31.3.

## Incomplete Features

1. **Attachment list/download/delete UI** — backend (upload + download endpoint) is complete; the notes template has the upload form only. No list, no download links, no delete action/endpoint.
2. **Observer cleanup** — `UserObserver` still has empty `updated/deleted/restored/forceDeleted` stubs (unused `Folder` import already removed).
3. **`notes.version` column** exists (`default 1`) but is never read or incremented.
4. **Test gaps** — no tests for: trash/restore/force-delete, attachment upload/download, search/sort behavior, observer seeding assertion, folder-ownership validation.

## Known Issues (ordered by severity)

1. **`saveNote()` trusts `noteFolderId` without ownership check** (`resources/views/components/notes/⚡index.blade.php:45-49`). A forged `folder_id` attaches the attacker's note to another user's folder (sequential ids), exposing note content to the folder owner. Fix with scoped validation before any multi-user exposure.
2. **`saveNote()` has no title validation** — `title` is `string(255)`; overlong input errors on MySQL strict mode.
3. **Pint style drift (5 files):** `app/Models/User.php` (`ordered_imports`), `app/Http/Controllers/Auth/GoogleController.php`, `app/Http/Controllers/NoteAttachmentController.php` (incl. unused `Request` import), `app/Providers/AppServiceProvider.php`, `routes/web.php` (inline FQCN → import). Run Pint with explicit paths (see 5).
4. **Search `LIKE` does not escape `%`/`_`** — semantics only (searching `%` matches everything); the OR group scoping is correct, no leak.
5. **Git object damage — REPAIRED 2026-10-11.** Thirteen zero-byte loose objects (incl. tip commit `80cca0c`, mtime 2026-10-09) broke `log`/`status` (`fatal: bad object HEAD`). Healed by deleting the 0-byte files and copying the healthy objects from a fresh GitHub clone (`cp -rn`, content-addressed so existing valid objects were untouched). `git fsck --full` is now clean (only harmless dangling blobs); `log`/`status` work. Remote `main` was byte-identical to the local tip, so nothing was lost. Root cause undetermined — likely interrupted write or WSL filesystem flakiness (same family as the sandbox-only `storage/framework/views/livewire/*` I/O errors).
6. **Google OAuth callback has no throttle.** Acceptable while Google-verified emails only; revisit before broad exposure.
7. **Could not verify:** PHPStan level 7 (timed out in sandbox — run `composer types:check` on dev machine); production asset build freshness; PHP upload limits vs the 10 MB rule.

## Test Status

- **Full suite: 37 passed / 94 assertions** (2026-10-11, `php:8.3-cli` container, in-memory SQLite). Includes new `NotesTest.php` and `GoogleControllerTest.php`.
- CI (`.github/workflows/tests.yml`): PHP 8.3 + Node 22, `composer setup` then `composer ci:check` (config:clear + pint + phpstan + tests).
- Sandbox caveat: this agent environment has no PHP; container runs need `-e VIEW_COMPILED_PATH=/tmp/vc` because the sandbox mount cannot read `storage/framework/views/livewire/*`. Unnecessary on the dev machine, where the app renders normally.

## Recommended Next Tasks

1. Add folder-ownership validation to `saveNote()` + regression test (forged cross-user `folder_id` → rejected). [Security]
2. Add title validation to `saveNote()` + test (overlong title). [Correctness]
3. Build attachment list/download-link/delete UI + endpoint + tests. [Feature — scope was discussed, decision pending]
4. Run Pint on the 5 drifted files; repair git HEAD. [Hygiene]
5. Run `composer types:check` on the dev machine (PHPStan unverified). [Verification]
6. Remove observer stubs; add seeding assertion test. [Hygiene]
7. Decide the fate of `notes.version` (use it or drop the column). [Cleanup]
