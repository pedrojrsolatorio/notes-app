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
| 7 | Note import and export | Slice 3 | planned |
| 8 | Offline use and device sync | Slice 4 | planned |
| 9 | Note reminders | Slice 5 | planned |
| 10 | Accessibility and responsive use | Slice 6 | planned |
| 11 | Performance as notes grow | Slice 7 | planned |

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
- [ ] Build it: `/develop more flexible organization`
  - [ ] Add the normalized name key and collision safe migration (AC-3, AC-7)
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
- [ ] Build it: `/develop quick capture`
  - [ ] Add the capture modal, text rule, and save path (AC-1, AC-2, AC-3, AC-7, AC-8, AC-11, AC-12)
  - [ ] Add the N shortcut, tooltip, and footer hints (AC-4, AC-10)
  - [ ] Add the floating trigger, Trash guards, and failure path (AC-5, AC-6, AC-9, AC-10)
- [ ] Verify it: `/check verify quick capture`
spec [0002](../specs/0002-quick-capture/index.md)

### 7. Note import and export · planned · Beta · needs a decision
Let you keep a usable copy of your notes outside the app and restore notes when needed.
**Done when:** you can export and restore notes with their formatting and attachments, and an invalid or repeated import does not silently replace existing notes.
- [ ] Design it (spec): `/architect note import and export`

### 8. Offline use and device sync · planned · Beta · needs a decision
Let you use notes across devices and keep working when a connection is unavailable.
**Done when:** your changes remain private to you, offline edits are kept, and changes from multiple devices do not silently lose note content.
- [ ] Design it (spec): `/architect offline use and device sync`

### 9. Note reminders · planned · needs a decision
Help you return to a note at a time you choose.
**Done when:** you can set, change, and clear a reminder, and receive its alert at the expected time.
- [ ] Design it (spec): `/architect note reminders`

### 10. Accessibility and responsive use · planned
Make common note tasks comfortable with keyboard, assistive technology, and narrow or wide screens.
**Done when:** you can complete common note tasks by keyboard and touch, with clear labels and feedback across screen sizes.
- [ ] Build it: `/develop accessibility and responsive use`

### 11. Performance as notes grow · planned · needs a decision
Keep browsing and searching comfortable as your collection becomes larger.
**Done when:** note browsing and search meet an agreed response target with a realistic collection size.
- [ ] Design it (spec): `/architect performance as notes grow`

## Deferred

- Shared notes, collaboration, and public publishing are outside the private solo use planned here.
- Product analytics, paid plans, and multiple language support are not needed for the current personal use goal.
- Global quick capture from the dashboard and profile is a later extension of Quick capture, after the notes page version is verified.

## Legend

- **Status** `planned` → `in-progress` → `done`, plus `existing` for features already in the app and `dropped` for features removed from the plan.
- **Needs a decision** means start with `/architect`; otherwise start with `/develop`.
- **Workflow** Alpha adds `/check verify` after `/develop`. Beta adds `/check verify` and `/test`. A feature with an unconfirmed decision can still be marked done, but its decision remains visible until resolved.
- **Code area** points to the current implementation of an existing feature.
