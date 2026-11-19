# Scope: Notes App

A private notes app for one person to capture, organize, and find notes in their daily routine. The measure of success is that you can save and retrieve notes as part of your normal habits.

**Build approach:** Tracer Bullet (each new feature works through the full app before the next one starts).
**Workflow:** Alpha (check each feature in the real app after building). Features that handle data portability or sync use Beta, with a check and dedicated tests.

_These are recommendations to keep your build orderly, not requirements. Skip anything that does not fit. You decide when a feature is done._

## At a glance

| # | Feature | Phase | Status |
|---|---------|-------|--------|
| 1 | Account access and security | Existing | existing |
| 2 | Note writing and rich text editing | Existing | existing |
| 3 | Note organization, search, and recovery | Existing | existing |
| 4 | File attachments | Existing | existing |
| 5 | More flexible organization | Slice 1 | in-progress |
| 6 | Quick capture | Slice 2 | in-progress |
| 7 | Note import and export | Slice 3 | in-progress |
| 8 | Offline use and device sync | Slice 4 | in-progress |
| 9 | Note reminders | Slice 5 | in-progress |
| 10 | Accessibility and responsive use | Slice 6 | in-progress |
| 11 | Performance as notes grow | Slice 7 | in-progress |

## Existing

### 1. Account access and security · existing
You can use a personal account with email or Google sign in, manage your profile, and protect access with two factor authentication or passkeys. Note and attachment access is scoped to the owner.
Code in `app/Actions/Fortify/`, `app/Http/Controllers/Auth/`, `app/Models/User.php`, and `resources/views/pages/settings/`.

### 2. Note writing and rich text editing · existing
You can create, edit, and save notes with a rich text editor.
Code in `resources/views/components/notes/⚡index.blade.php` and `resources/js/tiptap.js`.

### 3. Note organization, search, and recovery · existing
You can assign notes to folders, search and sort them, and move notes to trash or restore them.
Code in `app/Models/Folder.php`, `app/Models/Note.php`, and `resources/views/components/notes/⚡index.blade.php`.

### 4. File attachments · existing
You can upload, download, and delete files on your notes, with access limited to the note owner.
Code in `app/Models/NoteAttachment.php`, `app/Http/Controllers/NoteAttachmentController.php`, and `resources/views/components/notes/⚡index.blade.php`.

## Planned

### 5. More flexible organization · in-progress
Create, rename, and delete flat folders from the notes screen, while keeping notes safe and folder names unique within your account.
**Done when:** you can manage your folders with clear validation, and deleting a folder leaves its notes available in All Notes.
- [x] Design it (spec): `/architect more flexible organization`
- [x] Build it: `/develop more flexible organization`
  - [x] Add the normalized name key and collision safe migration (AC-3, AC-7)
  - [x] Create folders with validation and selection behavior (AC-1, AC-3, AC-5, AC-6, AC-8)
  - [x] Rename folders and preserve selection and ordering (AC-2, AC-3, AC-5, AC-6, AC-8)
  - [x] Delete folders without losing notes or attachments (AC-4, AC-5, AC-8)
- [ ] Verify it: `/check verify more flexible organization`
spec [0001](../specs/0001-manage-note-folders/index.md)
Code in `app/Models/Folder.php`, `database/migrations/2026_10_24_152100_add_name_key_to_folders_table.php`, and `resources/views/components/notes/⚡index.blade.php`.

### 6. Quick capture · in-progress
Make it faster to save a thought without first going through the full note editing flow.
**Done when:** you can start a note quickly with keyboard and touch, then find it in your notes.
- [x] Design it (spec): `/architect quick capture`
- [x] Build it: `/develop quick capture`
  - [x] Add the capture modal, text rule, and save path (AC-1, AC-2, AC-3, AC-7, AC-8, AC-11, AC-12)
  - [x] Add the N shortcut, tooltip, and footer hints (AC-4, AC-10)
  - [x] Add the floating trigger, Trash guards, and failure path (AC-5, AC-6, AC-9, AC-10)
- [ ] Verify it: `/check verify quick capture`
spec [0002](../specs/0002-quick-capture/index.md)
Code in `resources/views/components/notes/⚡index.blade.php` and `tests/Feature/QuickCaptureTest.php`.

### 7. Note import and export · in-progress · Beta
Let you keep a usable copy of your notes outside the app and restore notes when needed.
**Done when:** you can export and restore notes with their formatting and attachments, and an invalid or repeated import does not silently replace existing notes.
- [x] Design it (spec): `/architect note import and export`
- [x] Build it: `/develop note import and export`
  - [x] Add UUID backfill and the version 1 portable manifest mapping (AC-1, AC-4, AC-6, AC-7)
  - [x] Add encrypted export with attachment checks, sanitization, and archive limits (AC-1, AC-2, AC-8, AC-11, AC-14)
  - [x] Add strict preview validation, shared rate limits, staging, and expiry (AC-3, AC-5, AC-10, AC-11, AC-12)
  - [x] Add atomic additive restore, folder reuse, file validation, and skip reporting (AC-3, AC-4, AC-6, AC-7, AC-9, AC-12, AC-13)
  - [x] Add the profile settings workflow and completion states (AC-1, AC-2, AC-5, AC-10, AC-12, AC-13)
- [ ] Verify it: `/check verify note import and export`
- [x] Test it: `/test note import and export`
spec [0003](../specs/0003-note-import-export.md)
Code in `app/Services/NoteBackups/`, `app/Http/Controllers/NoteBackupController.php`, `resources/views/pages/settings/⚡import-export.blade.php`, `database/migrations/2026_11_01_150859_add_portable_ids_to_note_backup_records.php`, and `tests/Feature/NoteBackupTest.php`.

### 8. Offline use and device sync · in-progress · Beta
Let you use notes across devices and keep working when a connection is unavailable.
**Done when:** your changes remain private to you, offline edits are kept, and changes from multiple devices do not silently lose note content.
- [x] Design it (spec): `/architect offline use and device sync`
- [x] Build it: `/develop offline use and device sync`
  - [x] Add the sync change log, folder versions, and the sync API
  - [x] Add the installed app shell with the local store and offline editing
  - [x] Add folder sync, conflict copies, and session handling
- [ ] Verify it: `/check verify offline use and device sync`
- [x] Test it: `/test offline use and device sync`
spec [0004](../specs/0004-offline-use-and-device-sync/index.md)
Code in `app/Sync/`, `app/Http/Controllers/SyncController.php`, `resources/views/pwa/notes.blade.php`, `resources/js/offline-app.js`, and `database/migrations/`.

### 9. Note reminders · in-progress · Beta
Help you return to a note at a time you choose.
**Done when:** you can set, change, and clear a reminder, and receive its alert at the expected time.
- [x] Design it (spec): `/architect note reminders`
- [x] Build it: `/develop note reminders`
  - [x] Add reminder storage, local time handling, and notes page controls (AC-1, AC-2, AC-3)
  - [x] Queue generic email reminders with owner checked note links (AC-4, AC-5, AC-6, AC-7)
  - [x] Add retry, stale job, Trash, ownership, and offline sync boundaries (AC-5, AC-8, AC-9, AC-10, AC-11, AC-13)
  - [x] Keep reminders out of backups and restores (AC-12)
- [ ] Verify it: `/check verify note reminders`
    Automated feature and time conversion tests pass, and the reminder migration and every-minute scheduler are present. Browser verification stopped at the login page because no signed-in test account was available. Real mail delivery and queue worker retry timing were not exercised.
  - [x] Test it: `/test note reminders`
    The focused reminder suite passes 24 tests and 118 assertions. The full PHP suite passes 184 tests and 804 assertions. All 4 local time conversion tests pass.
  - [x] Document it: `/document pr`
spec [0005](../specs/0005-note-reminders.md)
Code in `app/Console/Commands/DispatchDueNoteReminders.php`, `app/Jobs/SendNoteReminder.php`, `app/Mail/NoteReminderMail.php`, `resources/views/components/notes/⚡index.blade.php`, `resources/js/note-reminder-time.js`, and `tests/Feature/NoteReminderTest.php`.

### 10. Accessibility and responsive use · in-progress
Make common note tasks comfortable with keyboard, assistive technology, and narrow or wide screens.
**Done when:** you can complete common note tasks by keyboard and touch, with clear labels and feedback across screen sizes.
- [x] Design it (spec): `/architect accessibility and responsive use`
- [x] Build it: `/develop accessibility and responsive use`
  - [x] Add the shared landmark, skip link, focus style, reduced motion, and dark theme plus forced colors baseline, and label fields, folder actions, and the editor toolbar with the More menu (AC-2, AC-3, AC-4, AC-11, AC-12)
  - [x] Make the note list keyboard operable, add stable focus keys and focus restoration, and change the quick capture shortcut (AC-1, AC-8, AC-13)
  - [x] Add the `/notes` status and validation behavior, the responsive three column and list or editor views, and the table width menu (AC-5, AC-6, AC-7, AC-9, AC-10, AC-14, AC-19)
  - [x] Add the offline semantics, full sync status, responsive layout, keyboard operation, focus moves, touch targets, sign in return path, and cold offline asset support (AC-15, AC-17)
  - [x] Add Pest DOM checks and run the existing notes, capture, reminder, attachment, and sync regression tests (AC-16, AC-18)
- [ ] Verify it: `/check verify accessibility and responsive use`
spec [0006](../specs/0006-accessibility-and-responsive-use/index.md)

### 11. Performance as notes grow · in-progress
Keep browsing and searching comfortable as your collection becomes larger.
**Done when:** note browsing and search meet an agreed response target with a realistic collection size.
- [x] Design it (spec): `/architect performance as notes grow`
- [ ] Build it: `/develop performance as notes grow`
  - [ ] Add the title key, indexes, backfill, and sync payload (AC-3, AC-7, AC-11)
  - [ ] Add the paged server read, cursor validation, Load more, and preview bounds (AC-1, AC-2, AC-4, AC-8, AC-9, AC-10)
  - [ ] Add search, folder, Trash, sort, and focus behavior (AC-2, AC-3, AC-4, AC-5, AC-8)
  - [ ] Add offline paging and the benchmark checks (AC-6, AC-10, AC-12)
  - [ ] Run the focused and full regression suites (AC-11)
- [ ] Verify it: `/check verify performance as notes grow`
spec [0007](../specs/0007-performance-as-notes-grow/index.md)

## Deferred

- Shared notes, collaboration, and public publishing are outside the private solo use planned here.
- Product analytics, paid plans, and multiple language support are not needed for the current personal use goal.
- Global quick capture from the dashboard and profile is a later extension of Quick capture, after the notes page version is verified.
- A light theme with verified WCAG 2.2 AA contrast is a follow-up from Accessibility and responsive use (spec 0006).
- A formatting toolbar in the offline editor is a later extension of Offline use and device sync (spec 0006).
- A repeatable browser accessibility check in CI waits for an approved test dependency (spec 0006).
- SQLite full text search for notes if the benchmark shows search is the bottleneck (from spec 0007).
- Snapshot tokens for concurrent edits to active sort keys if page reordering remains noticeable (from spec 0007).
- A repeatable browser timing check in CI when approved browser tooling is available (from spec 0007).

## Legend

- **Status** `planned` → `in-progress` → `done`, plus `existing` for features already in the app and `dropped` for features removed from the plan.
- **Needs a decision** means start with `/architect`; otherwise start with `/develop`.
- **Workflow** Alpha adds `/check verify` after `/develop`. Beta adds `/check verify` and `/test`. A feature with an unconfirmed decision can still be marked done, but its decision remains visible until resolved.
- **Code area** points to the current implementation of an existing feature.
