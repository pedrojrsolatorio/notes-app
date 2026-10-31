# 0003. Note import and export

**Date**: 2026-10-30
**Status**: Proposed

## Summary

This spec adds a private backup flow for notes, folders, and attachments so a user can export their data and restore it into the same app later. The backup is encrypted before it leaves the server, the archive is validated before import, and repeated or already imported items are skipped instead of overwriting current edits. The feature keeps the app's existing owner scoping and private storage rules, and it treats every import as an additive restore.

## Context

The app already keeps notes, folders, and attachments owned by the signed in user, and it stores attachments on the private local disk. The next need is a controlled way to move that data out of the app for backup and recovery, without exposing the data to a user who is not signed in or hiding unsafe HTML in the content.

A backup cannot be treated like a plain zip of visible files. Notes are rich text HTML, attachments are private files, and folder names are normalized to match account data. The feature must preserve the current note structure, keep dates and trash state, and never silently replace a note that already exists. The app should fail clearly when a backup is invalid, when an import would exceed the accepted size limits, or when a file is missing or unreadable.

The chosen design follows the app's current security model: every note, folder, and attachment stays scoped to the signed in user, the archive password is never stored, and the actual encrypted payload is only decrypted during preview or restore. The export process also fails when the backup would exceed the accepted archive budget rather than producing a partial backup that cannot be restored.

## Requirements

**User stories**:
- As a notes owner, I want to export my notes and attachments so that I can keep a private backup outside the app.
- As a notes owner, I want to restore a backup into this app so that I can recover my collection without losing current work.
- As a notes owner, I want repeated or already imported items to be skipped with clear feedback so that imports stay safe and predictable.
- As a signed in user, I want import and export actions to stay private and bounded so that my data is not exposed or abused.

**Acceptance criteria** (the contract, each criterion is independently checkable):
- **AC-1**: From the profile settings area, a user can export all of their notes, folders, and attachments as a single encrypted backup archive. The exported file includes metadata required to restore folder links, note links, attachment links, trash state, and timestamps, but it does not contain source account IDs or server file paths.
- **AC-2**: The backup archive is password protected with a password entered by the user at export time. The password is not stored in the database, cache, or logs. The app requires the same password again on final confirmation before the archive is created for download.
- **AC-3**: The app rejects invalid archives before import. Invalid JSON, missing payload metadata, missing or unreadable encrypted content, and wrong password all stop the import with a clear error and no partial writes.
- **AC-4**: An import is additive only. A note, folder, or attachment with an existing portable ID for this account is skipped. An already imported note is not overwritten, and a later backup cannot silently replace an existing note or attachment.
- **AC-5**: A restore preview shows counts for folders, notes, attachments, and repeated or skipped items before the user confirms. It does not show a password in plain text or reveal the file path of any attachment.
- **AC-6**: The app reuses destination folders with the same normalized name when a folder is not already present for this account. New folders keep the original created and updated timestamps from the backup. Existing folder names that already match are kept and not overwritten.
- **AC-7**: The app preserves note and attachment state as part of import: title, content, folder link, trash state, created timestamp, updated timestamp, and attachment original name, MIME type, file size, and created timestamp. If a note already exists, any unseen attachment is added to that note without changing the note text or metadata.
- **AC-8**: Imported note content is sanitized before it is stored. The sanitizer keeps the app's supported rich text formatting but removes unsafe HTML, inline styling, file links, and any dangerous URL scheme such as `javascript:` or `data:`. The same sanitization is applied during export and import so stale content is never reintroduced.
- **AC-9**: Imported attachments follow the existing file validation rules and are limited to the same file types and 10 MB per file size as ordinary note uploads. An invalid file in a backup fails the whole import, and the backup remains protected from partial writes.
- **AC-10**: Export and import actions are throttled per signed in account. The app allows up to five preview or import attempts per minute per account and uses the same limit for backup generation. A preview token expires after 15 minutes, and a cancelled preview or successful import immediately deletes the staged archive.
- **AC-11**: The app fails clear when an export would exceed the supported archive limits. If the archive exceeds 100 MB or the expanded payload would exceed 500 MB, the export stops before the file is returned and the user sees the reason.
- **AC-12**: Empty backups remain valid when a user has no notes, folders, or attachments. The preview shows zero counts, and the restore result creates no records.
- **AC-13**: The app shows a final import result with counts and the names of skipped items and their reasons. A repeated or invalid item is not silently dropped from the result summary.
- **AC-14**: The app validates that all linked attachments for the notes in a backup exist and are readable before export succeeds. A missing or unreadable attachment fails the export that would otherwise generate an incomplete backup.

## Options considered

### Option 1: Private encrypted payload inside a ZIP archive

Store one encrypted JSON payload and attachment files in a ZIP archive. Use a password derived by the app with Argon2id and an authenticated libsodium stream, then keep the password out of the app. Validate the archive and payload before import, and ensure restore only adds unseen items.

**Pros**:
- Stronger password hardening than a ZIP AES header based approach.
- Keeps the app in control of the actual security boundary and payload format.
- Fits the notes app's private, owner scoped data model and can hide the encrypted content behind a normal ZIP wrapper.

**Cons**:
- A user needs the app to restore the archive, so the backup is not a plain zip you can inspect outside the app.
- The payload format and restore code become part of the app's long term compatibility surface.

### Option 2: Plain ZIP with raw HTML and attachment files

Write a ZIP with a folder structure and files in their original form, without app level encryption. This is the simplest to package and inspect, but it exposes the full backup contents if the file is copied.

**Pros**:
- Easy to inspect and share outside the app.
- Simplest implementation for a first pass.

**Cons**:
- No password protection and no safe separation of public from private data.
- Fails the app's privacy goal for note contents and attachments.
- It becomes easy to overwrite existing notes by accident.

### Option 3: Local account export only

Export only to a local file in the same signed in account, without a transportable archive or password. This keeps the backup within the app state.

**Pros**:
- Smallest code footprint.
- Good for temporary recovery on one device.

**Cons**:
- Does not meet the requirement for a usable copy outside the app.
- Does not help a user move notes to another device or recover after a full account reset.

## Decision

**Chosen option**: Option 1: Private encrypted payload inside a ZIP archive.

The app will export a single ZIP archive containing a versioned encrypted payload and attachment files, then require the user to enter the password again at final confirmation. The archive is decrypted only inside the app, and import stays additive so no existing note or attachment is replaced.

**Implementation skills**: `laravel-best-practices` (`pedrojrsolatorio/notes-app`, `.agents/skills/laravel-best-practices/`) · `livewire-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/livewire-development/`) · `fluxui-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/fluxui-development/`) · `tailwindcss-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/tailwindcss-development/`) · `testing-best-practices` (`pedrojrsolatorio/notes-app`, `.agents/skills/testing-best-practices/`)

## Rationale

The app already uses a private storage disk and owner scoped note, folder, and attachment queries. A backup needs to preserve that boundary and the encrypted payload does so without changing the overall account model. The app level choice also matches the stronger password derivation requirement, while the ZIP wrapper still gives us a single file for download and a short lived staging area before import.

The stronger option is the right fit for a personal notes app where the backup is meant to be private and portable, but not public or inspectable without the app. Using the app to decrypt is a tradeoff, and that tradeoff is acceptable under the current Beta scope because the feature is meant to help recovery inside the same app. The runner up, a plain ZIP without encryption, would make the file easy to inspect but it would also make the notes and attachments easy to read if the file were copied or shared.

The current file upload validation and sanitized HTML rules already define the right boundary for attachments and note text. Reusing those checks during import keeps the app consistent and prevents a backup from bypassing the same validation rules the normal upload flow already enforces. The import remains additive and skips repeated items rather than writing over current data, which is the safest choice for a personal notes collection.

## Feature design

**Data model sketch**:

| Entity | Fields | Relationships and constraints |
|---|---|---|
| Folder | `id`, `user_id`, `name`, `name_key`, `portable_id`, timestamps | One user has many folders. `portable_id` is unique within one account and used for backup matching. `user_id` and `name_key` remain unique after normalization. |
| Note | `id`, `user_id`, `folder_id`, `title`, `content`, `deleted_at`, `version`, `portable_id`, timestamps | One user has many notes. A note belongs to zero or one folder. `portable_id` is unique within one account and is used to detect duplicates during import. `deleted_at` preserves Trash state. |
| NoteAttachment | `id`, `note_id`, `user_id`, `original_name`, `file_path`, `mime_type`, `file_size`, `portable_id`, timestamps | One note has many attachments. An attachment belongs to one user and one note. `portable_id` is unique within one account. The file path stays inside the user's private attachment directory. |

The `portable_id` value is a stable UUID stored in the database. The app uses it to match a backup item to an existing owner record and to skip or add unseen items without replacing current edits. Local database IDs are never copied across accounts. The archive contains only `portable_id`, not server file paths or the source account ID.

**State transitions**:
- Empty account → export generates a valid archive with zero counts.
- Valid archive → preview validates and computes counts.
- Preview confirmed → import creates new records and skips those already present.
- Wrong password → preview or import fails without writing a new record.
- Missing attachment or invalid file → export or import fails cleanly with no partial writes.
- Cancelled or expired preview → staged archive deleted.

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `/settings/import-export` | `GET` | Signed in session | Page with export, import, and preview state | Authenticated and verified user | Redirect if not signed in or verified |
| `/settings/import-export/export` | `POST` | `password`, optional `confirm_password` | Downloaded ZIP or validation error | Authenticated and verified user | 422 invalid password, 413 archive size exceeded |
| `/settings/import-export/preview` | `POST` | `archive`, `password` | Summary with folder, note, attachment, and skip counts | Authenticated and verified user | 400 invalid archive, 422 wrong password |
| `/settings/import-export/import` | `POST` | `preview_token`, `password` | Created counts, skipped counts, and names of skipped items | Authenticated and verified user | 400 invalid token, 422 wrong password, 409 invalid archive payload |
| `/settings/import-export/cancel` | `POST` | `preview_token` | Stage removed and user returned to the settings page | Authenticated and verified user | 404 missing or expired preview |

**Value sourcing**:

| Action | Value produced or displayed | Source |
|---|---|---|
| Export page | Archive size | Derived from the generated payload and attachment files |
| Preview summary | Folder, note, attachment, and skip counts | Archive payload, user scoped database, and duplicate checks |
| Import decision | Whether a note already exists | `portable_id` lookup in the current user's notes |
| Import decision | Whether a folder already exists | `portable_id` lookup, then exact normalized name fallback within the current user |
| Restore note | Note title and HTML content | Data inside the encrypted package, sanitized before save |
| Restore attachment | Original name, MIME type, size, file content | Attachment payload inside the encrypted package and stored file path in the user's private disk |
| Download response | File download | Staged encrypted archive on the private disk |
| Preview status | Expiry time | Preview token creation timestamp and configured 15 minute lifetime |

**Key invariants**:
- A password is never stored in the database, cache, or session.
- Every imported record is assigned to the signed in user account.
- `portable_id` is unique within one user account and is used to detect repeated imports.
- A note is never overwritten if the same portable ID exists for the user.
- Attachments are never written outside that user's private note attachments directory.
- Export only succeeds when the archive stays under 100 MB and expanded content stays under 500 MB.
- An archive is only considered valid after the encrypted payload is successfully authenticated and the final stream tag is verified.

**Security model**:
- All export and import actions require the signed in and verified user.
- Each archive is encrypted before it leaves the app's private storage path.
- No user can import another account's archive into the current account because the payload is validated and scoped to the signed in user.
- The feature does not add public APIs or cross account sharing. The account owner remains the only person who can access their backup, preview, or restore path.
- The app sanitizes HTML on both import and export so stale note content cannot reintroduce unsupported or unsafe markup.

**Configuration required**:
- `IMPORT_EXPORT_PREVIEW_TTL_MINUTES`: number of minutes a staged archive remains valid before expiry.
- `IMPORT_EXPORT_MAX_ARCHIVE_BYTES`: maximum ZIP size, fixed at 100 MB for this feature.
- `IMPORT_EXPORT_MAX_EXPANDED_BYTES`: maximum expanded content size, fixed at 500 MB.
- `IMPORT_EXPORT_RATE_LIMIT_PER_MINUTE`: per account import and preview limit, fixed at 5 per minute.
- `MEWS_PURIFIER_CONFIG`: the explicit HTML and CSS allowlist used by the backup sanitizer.

**Critical test scenarios** (each maps to an acceptance criterion in ## Requirements):
- Happy path: a valid archive with notes, folders, and attachments previews and restores into the signed in account, verifies **AC-1**, **AC-2**, **AC-5**, **AC-6**, **AC-7**, **AC-12**.
- Failure case: wrong password, invalid payload, or a missing attachment prevents import and leaves no partial writes, verifies **AC-3**, **AC-9**, **AC-11**, **AC-14**.
- Import safety: an archive that repeats a `portable_id` or contains a note already in the account is skipped and reported, verifies **AC-4**, **AC-13**.
- Sanitization: unsafe HTML or malicious URL schemes are stripped on import and export, verifies **AC-8**.
- Rate limit and expiry: preview and import attempts are throttled and the preview token expires or is cancelled cleanly, verifies **AC-10**.

## Build plan

1. Add the new `portable_id` columns and the per account unique indexes for folders, notes, and attachments, plus the data mapping needed for backup matching, satisfies **AC-1**, **AC-4**, **AC-6**, **AC-7**.
2. Add the export path and staged archive storage, using the signed in user, private disk, and password confirmation flow, satisfies **AC-1**, **AC-2**, **AC-11**, **AC-14**.
3. Add the preview validation, rate limiting, and the 15 minute preview token expiry flow, satisfies **AC-3**, **AC-5**, **AC-10**.
4. Add the import pipeline with archive validation, payload authentication, and additive restore logic, satisfies **AC-3**, **AC-4**, **AC-6**, **AC-7**, **AC-13**.
5. Add the sanitization layer and the attachment validation pass for imported content and files, satisfies **AC-8**, **AC-9**.
6. Add the import result summary and empty backup behavior, satisfies **AC-5**, **AC-12**, **AC-13**.
7. Add final UI and settings page wiring for export, preview, import, and cancel actions, satisfies **AC-1**, **AC-5**, **AC-10**, **AC-12**.

## Consequences

**Positive**:
- Users can keep a private backup outside the app and restore it with a password.
- Restore is additive and safe, so current note edits remain intact.
- The feature reuses the app's existing owner scoping and private file storage patterns.
- The archive validation and review steps make invalid backups visible before they can damage the account.

**Negative / tradeoffs**:
- The backup is not directly readable as a plain ZIP without the app and password.
- The app must maintain a dedicated restore format and compatibility behavior across future versions.
- Preview and import validation add operational overhead and rate limiting requirements.
- The feature introduces a more complex archive pipeline and a staged file cleanup process.

**Neutral**:
- The feature needs a small amount of configuration for archive limits and preview expiry.
- A later version may need a dedicated export compatibility version if the payload format changes.

## Follow-up

- [ ] Add the `mewebstudio/Purifier` package and configure the HTML and CSS allowlist for the supported note editor styles before implementation begins.
- [ ] Add a small cleanup task for expired staged backup files and ensure the task deletes previews on cancel and after a successful import.
- [ ] Add the import and export settings page to the profile settings flow, then connect the routes to the shared notes layout.

## References

**Project sources**:
- `AGENTS.md` for the private local storage model and account ownership rules.
- `docs/scope/scope.md` for the Beta feature intent and done condition.
- `app/Models/Note.php`, `app/Models/Folder.php`, `app/Models/NoteAttachment.php`, and `routes/settings.php` for the existing data model and settings layout.
- `NoteAttachmentController` owner check pattern as the reference for safe file access.

**Practices & standards**:
- Owner scoped queries for every note, folder, and attachment action.
- Explicit allowlist sanitization for HTML and URLs rather than trust of raw editor output.
- Rate limiting for sensitive and expensive account operations.
- Additive restore and skip on duplicate IDs to prevent silent overwrites.
