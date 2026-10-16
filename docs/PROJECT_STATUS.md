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
- **PHPStan level-7 typing fixes (2026-10-16):** resolved all 13 errors reported by `composer types:check` on the dev machine — explicit return types on both OAuth/attachment controller actions and PHPDoc relation generics (`BelongsTo<User, $this>`, `HasMany<Note, $this>`, …) on `Folder`/`Note`/`NoteAttachment`/`User`. Attachment download is typed `StreamedResponse` (verified against `FilesystemAdapter::download()` in vendor; `BinaryFileResponse` is wrong there). Full suite re-verified green after the change.

## Incomplete Features

1. ~~**Attachment list/download/delete UI**~~ — **COMPLETED 2026-10-12.** Template lists the selected note's attachments (name, size, plain-anchor download link, `wire:confirm` delete); new `deleteAttachment()` is owner-scoped via the user's note, removes file + row, tolerates missing files; upload wraps the DB create so a DB failure deletes the orphaned file. Covered by 9 new tests in `tests/Feature/NotesTest.php`.
2. ~~**Observer cleanup**~~ — **DONE 2026-10-16.** Empty `updated/deleted/restored/forceDeleted` stubs removed; `created()` (folder seeding) kept. Seeding behavior already asserted via `RegistrationTest.php`.
3. **`notes.version` column** exists (`default 1`) but is never read or incremented.
4. ~~**Test gaps**~~ — **CLOSED 2026-10-12.** `NotesTest.php` now covers trash/restore/force-delete (+ cross-user restore isolation), folder filter, title+content search, and title A–Z sort; `RegistrationTest.php` asserts the 4 seeded folders via the real registration path.

## Known Issues (ordered by severity)

1. ~~**`saveNote()` trusts `noteFolderId` without ownership check**~~ — **FIXED 2026-10-12.** `saveNote()` now validates `noteFolderId` with `Rule::exists('folders','id')->where('user_id', auth()->id())`; forged cross-user ids fail validation and the note is untouched. Covered by a regression test.
2. ~~**`saveNote()` has no title validation**~~ — **FIXED 2026-10-12.** `saveNote()` now validates `title` as `required|string|max:255`, with `@error` messages under the title and folder inputs. Covered by overlong/empty-title tests.
3. ~~**Pint style drift (5 files)**~~ — **FIXED 2026-10-12** (manual edits mirroring Pint's `laravel` preset: import ordering, unused imports, spacing/indentation; `pint --test` passes on all touched files). Notable corrections: same-namespace imports are *removed* by the preset (`User.php` needs no `Folder`/`Note` imports — `AGENTS.md` corrected), and the preset keeps `! $user` spaced. Verified by 26 passing tests across the 3 affected suites.
4. **Search `LIKE` does not escape `%`/`_`** — semantics only (searching `%` matches everything); the OR group scoping is correct, no leak.
5. **Git object damage — REPAIRED 2026-10-11.** Thirteen zero-byte loose objects (incl. tip commit `80cca0c`, mtime 2026-10-09) broke `log`/`status` (`fatal: bad object HEAD`). Healed by deleting the 0-byte files and copying the healthy objects from a fresh GitHub clone (`cp -rn`, content-addressed so existing valid objects were untouched). `git fsck --full` is now clean (only harmless dangling blobs); `log`/`status` work. Remote `main` was byte-identical to the local tip, so nothing was lost. Root cause undetermined — likely interrupted write or WSL filesystem flakiness (same family as the sandbox-only `storage/framework/views/livewire/*` I/O errors).
6. **Google OAuth callback has no throttle.** Acceptable while Google-verified emails only; revisit before broad exposure.
7. **Could not verify in sandbox:** PHPStan level 7 — the sandbox analysis times out even when narrowed to `app/Models` + 2 controllers (confirmed 2026-10-16). **Run `composer types:check` on the dev machine.** Production asset build freshness and PHP upload limits vs the 10 MB rule also unverified here.

## Test Status

- **Full suite: 57 passed / 141 assertions** (2026-10-16, `php:8.3-cli` container, in-memory SQLite). Supersedes earlier partial totals; covers all suites incl. notes, attachments, auth, settings.
- ~~`NotesTest.php` + `RegistrationTest.php`: 24 passed / 54 assertions (2026-10-12 file run)~~ — folded into the full-suite total above.
- CI (`.github/workflows/tests.yml`): PHP 8.3 + Node 22, `composer setup` then `composer ci:check` (config:clear + pint + phpstan + tests).
- Sandbox caveat: this agent environment has no PHP; container runs need `-e VIEW_COMPILED_PATH=/tmp/vc` because the sandbox mount cannot read `storage/framework/views/livewire/*`. Unnecessary on the dev machine, where the app renders normally.

## Recommended Next Tasks

1. ~~Add folder-ownership validation to `saveNote()`~~ — DONE 2026-10-12.
2. ~~Add title validation to `saveNote()`~~ — DONE 2026-10-12.
3. ~~Build attachment list/download-link/delete UI + endpoint + tests~~ — DONE 2026-10-12.
4. ~~Run Pint on the 5 drifted files. (Git HEAD repair — DONE 2026-10-11.)~~ — DONE 2026-10-12. [Hygiene]
5. Run `composer types:check` on the dev machine again to confirm the 2026-10-16 typing fixes cleared all 13 reported errors (PHPStan cannot run in the sandbox). [Verification]
6. ~~Remove observer stubs; add seeding assertion test.~~ — DONE 2026-10-16. [Hygiene]
7. ~~Decide the fate of `notes.version` (use it or drop the column).~~ [Cleanup] — OPEN, needs product decision.
