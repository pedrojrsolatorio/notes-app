# 0003. Note import and export

**Date**: 2026-10-30
**Status**: Proposed

## Summary

This spec adds a private backup flow for notes, folders, and attachments so you can export your data and restore it later. The complete backup, including attachment files, is encrypted before it leaves the server, and imports are checked before they can add data. A clear settings flow and additive restore protect your current notes and explain what was skipped.

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
- **AC-1**: From profile settings, a user can export all of their notes, folders, and attachments as one encrypted backup archive. The archive includes the metadata needed to restore links, Trash state, and timestamps, but contains no source account IDs or server file paths. User data metadata and attachment bytes are encrypted. The outer format description and encrypted payload size remain visible.
- **AC-2**: The backup uses Argon2id version 1.3 to derive a 32 byte key from the UTF-8 password bytes exactly as entered, a random 16 byte salt, three operations, and 64 MiB of memory. It encrypts the complete inner ZIP in 64 KiB chunks with libsodium secretstream using XChaCha20-Poly1305, and requires the final authentication tag before accepting the payload. The app reports authentication failure as one generic error for an incorrect password or a damaged archive. The password is never persisted in the database, cache, session, or logs. Export requires matching password and confirmation fields.
- **AC-3**: The app rejects invalid archives before import. Invalid JSON, missing payload metadata, missing or unreadable encrypted content, failed authentication, unexpected ZIP entries, duplicate entries or JSON keys, unsafe paths, and symlinks all stop the import with a clear error and no partial writes.
- **AC-4**: An import is additive only. A folder with an existing portable ID is reused without changes. A note with an existing portable ID is not changed, though unseen attachments may be added to it. An attachment with an existing portable ID is skipped. A later backup cannot silently replace an existing note or attachment.
- **AC-5**: The profile settings flow provides export and import actions. Import shows a file and password form, then a preview with folder, note, attachment, reused folder, and skipped counts before restore confirmation. The user reenters the password to restore, so the app does not retain it after preview. The flow shows phase based progress, validation errors, expired previews, and a final result with created, reused, and skipped counts plus skipped item names and reasons. Passwords are never displayed in plain text, and attachment file paths are never shown.
- **AC-6**: The app reuses destination folders with the same normalized name when a folder with the same portable ID is not already present for this account. Folder normalization uses `Folder::normalizeName()` and `Folder::nameKeyFor()`. New folders keep the original created and updated timestamps from the backup. Existing folders are never renamed or overwritten.
- **AC-7**: The app preserves note and attachment state as part of import: title, content, folder link, trash state, created timestamp, updated timestamp, and attachment original name, MIME type, file size, and created timestamp. If a note already exists, any unseen attachment is added to that note without changing the note text or metadata.
- **AC-8**: Note content is sanitized on export into the backup and again before import, without changing the stored source note. The sanitizer keeps the app's supported rich text formatting, allows only the specified safe tags, attributes, and CSS properties, and removes unsupported styling, file links, and dangerous URL schemes such as `javascript:` or `data:`.
- **AC-9**: Imported attachments use the existing `attachmentValidationRules()` policy: PDF, DOC, DOCX, TXT, RTF, ODT, XLS, XLSX, PPT, PPTX, CSV, JPG, JPEG, and PNG, up to 10 MiB per file. An invalid file fails the whole import before writes begin, and the failure identifies the item and reason.
- **AC-10**: Export, preview, and import share one limit of five attempts per signed in account per minute. Invalid passwords, invalid archives, and other failed attempts count. A throttled action returns a retry time and a clear message. A preview token expires after 15 minutes, and a cancelled preview or successful import immediately deletes the staged archive.
- **AC-11**: The app fails clearly when an export or import exceeds the supported archive limits. The downloaded or uploaded outer ZIP is limited to 104857600 bytes. The uncompressed inner ZIP contents, including the manifest, are limited to 524288000 bytes. Each attachment is limited to 10485760 bytes. The app enforces these limits while streaming and staging, before returning an export or writing imported records.
- **AC-12**: Empty backups remain valid when a user has no notes, folders, or attachments. The preview shows zero counts, and the restore result creates no records.
- **AC-13**: The app shows a final import result with counts and the names of skipped items and their reasons. Repeated items are reported as skipped. Invalid archives fail before writes and show the invalid item and reason instead of treating it as a skipped item.
- **AC-14**: The app validates that all linked attachments for the notes in a backup exist and are readable before export succeeds. A missing or unreadable attachment fails the export that would otherwise generate an incomplete backup.

## Options considered

### Option 1: Private encrypted payload inside a ZIP archive

Store the encrypted contents in a ZIP archive. Derive a key from the password with Argon2id and encrypt the full inner ZIP with libsodium secretstream. Validate the archive and payload before import, and ensure restore only adds unseen items.

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

The outer ZIP contains a versioned format description and one encrypted payload. That payload is an inner ZIP containing the manifest, notes, folders, and attachment bytes, so filenames and content are encrypted together. The user enters the password and confirmation to export, then enters the password again to restore after preview. The importer accepts only the supported Argon2id profile, authenticates the complete stream before trusting its contents, and never replaces existing records.

The key uses Argon2id version 1.3 with a random 16 byte salt, three operations, and 64 MiB of memory to produce 32 bytes. The encrypted payload begins with the libsodium secretstream header, followed by 64 KiB authenticated chunks and a required final tag. The outer format description identifies the format version, derivation algorithm and parameters, and encryption algorithm. The importer rejects unsupported algorithms, parameter values, missing final tags, and invalid authentication before writing records.

The stream header has the libsodium defined length. Each following record has a four byte big endian ciphertext length and one secretstream ciphertext message. Each message contains no more than 65536 plaintext bytes plus the secretstream authentication overhead. The final message must carry the final tag, and any missing tag, repeated final tag, malformed length, or trailing bytes rejects the archive. Lengths are checked before allocating or reading message data.

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

The `portable_id` value is a stable UUID version 4 stored in the database. The migration backfills one for every existing folder, note, and attachment. New records receive one when created. The app uses it to match a backup item to an existing owner record and to skip or add unseen items without replacing current edits. Local database IDs are never copied across accounts. The archive contains only `portable_id`, not server file paths or the source account ID.

**Backup format**:
- The outer ZIP contains exactly `format.json` and `payload.bin`. `format.json` is plaintext and contains exactly `format_version: 1`, `kdf: argon2id13`, a base64 encoded 16 byte salt, `opslimit: 3`, `memlimit: 67108864`, and `cipher: secretstream_xchacha20poly1305`. `payload.bin` contains the secretstream header and authenticated ciphertext.
- The decrypted payload is an inner ZIP containing `manifest.json` and attachment bytes under `attachments/{portable_id}`. The manifest contains exactly `format_version: 1` and `folders`, `notes`, and `attachments` arrays. Folder items contain a UUID string `portable_id`, string `name`, and RFC 3339 UTC `created_at` and `updated_at` timestamps. Note items contain a UUID string `portable_id`, nullable UUID string `folder_portable_id`, string `title` of at most 255 characters, nullable string `content`, nullable RFC 3339 UTC `deleted_at`, and RFC 3339 UTC `created_at` and `updated_at` timestamps. Attachment items contain a UUID string `portable_id`, UUID string `note_portable_id`, string `original_name`, nullable string `mime_type`, integer `file_size`, RFC 3339 UTC `created_at`, and string `payload_path`. Each `payload_path` must equal `attachments/{portable_id}`. Relationships use portable IDs only. Every non null folder and note reference must resolve to one manifest item. Neither manifest nor files contain local database IDs, source account ID, or server file paths.
- Timestamps use RFC 3339 UTC text with six fractional digits. Import rejects invalid timestamps and does not apply the source timezone. The local note `version` is not part of the backup format; imported notes start at version `1`.
- The outer ZIP is limited to 104857600 bytes. The uncompressed inner ZIP contents, including the manifest, are limited to 524288000 bytes. `format.json` is limited to 65536 bytes, `manifest.json` to 16777216 bytes, and the manifest to 100000 total folder, note, and attachment records. Each attachment is limited to 10485760 bytes. The importer enforces limits while reading and does not trust declared sizes.
- Manifest JSON must be valid UTF-8 with no duplicate object keys. Portable IDs must be valid UUID version 4 strings. Attachment paths must be unique and each must refer to exactly one attachment item. `file_size` must equal the actual attachment bytes. The archived MIME type is retained as descriptive metadata, but the actual file bytes must pass the existing upload validator before import.
- Reject unexpected outer or inner ZIP entries, duplicate entries, absolute paths, path traversal, and symlinks before extracting archive content. The only inner entries allowed are `manifest.json` and files at `attachments/{portable_id}`. Enforce expanded byte and entry limits while streaming and staging.

**Duplicate handling**:
- A portable ID repeated within the same manifest entity array makes the archive invalid. Reject it before writes and include the item name and reason in the validation error.
- A folder with an existing portable ID maps to that folder without changing its name or timestamps, even if its current name differs from the archive name. Otherwise, a folder with the same normalized name maps to the existing folder without replacing that folder's portable ID or timestamps. Only a folder with neither match is created.
- A note with an existing portable ID is not changed. Its unseen attachments are still added to that existing note. An attachment with an existing portable ID is skipped. New notes and attachments are created with the archive portable IDs.
- Preview counts describe the restore decisions. A reused folder counts as reused, a matching note counts as skipped, and each matching attachment counts as skipped. Final results report created, reused, and skipped counts separately, with skipped item names and reason codes. Stable reason codes are `portable_id_exists` and `note_already_exists`.
- Any invalid item rejects the entire archive before database or final attachment writes. The error summary names each invalid item and its reason. Invalid items are not reported as skips.

**Sanitization policy**:
- Allow only the editor's supported elements: `p`, `h1` through `h3`, `blockquote`, `pre`, `code`, `br`, `hr`, `ul`, `ol`, `li`, `strong`, `b`, `em`, `i`, `u`, `s`, `del`, `mark`, `a`, `table`, `thead`, `tbody`, `tr`, `th`, `td`, `label`, `span`, and `div`.
- Allow only attributes needed by those elements: link `href`, `target`, and `rel`; table `colspan`, `rowspan`, and `colwidth`; task list `data-type` and `data-checked`; and disabled checkbox `type` and `disabled`. Link targets are limited to `_blank`, and `rel` is normalized to `noopener noreferrer nofollow`. Table spans and widths must be positive integers. Task types are limited to `taskList` and `taskItem`, and task checked state is limited to `true` or `false`. Drop event handlers, arbitrary classes and IDs, form controls other than disabled task list checkboxes, and all other attributes.
- Allow only `text-align`, `font-size`, `color`, and `background-color` in inline style. Text alignment is limited to `left`, `center`, `right`, and `justify`. Font size is limited to `12px`, `14px`, `16px`, `18px`, `20px`, `24px`, `28px`, and `32px`. Color values are limited to six digit hexadecimal colors. All other CSS properties and values are removed.
- Allow only `http`, `https`, and `mailto` links. Remove file links, protocol relative links, and any other URL scheme.
- Export sanitization changes only the backup copy. Import sanitization runs again before storage. The source note is not modified by export.

**Import transaction behavior**:
- Decrypt and fully validate the archive, manifest, and every attachment before starting database writes.
- Extract validated files into a unique private staging directory. In one database transaction, create or reuse folders and notes, then install new attachment files under the owner's private attachment directory and create their records.
- If a database or storage operation fails, roll back database changes and remove every attachment file created by that attempt. Delete decrypted staging data and the staged archive on failure, cancellation, expiry, and success. Do not expose a successful result until the transaction and file installation both complete.
- Cleanup failures are logged without logging passwords or note content. The expiry cleanup task also removes orphaned staging files left by an interrupted request.

**State transitions**:
- Empty account → export generates a valid archive with zero counts.
- Valid archive → preview validates and computes counts.
- Preview ready → password reentered and restore confirmed → import creates new records and skips those already present.
- Incorrect password or damaged encrypted stream → preview or import reports the same authentication error without writing a new record.
- Invalid authentication tag or missing final tag → import fails without writing a new record.
- Missing attachment or invalid file → export or import fails cleanly with no partial writes.
- Cancelled or expired preview → staged archive deleted.

**Screens and states**:
- The profile settings page has Backup and restore sections for export and import.
- Export starts with password and confirmation fields. It shows a generating state, validation or size errors, or a successful archive download.
- Import starts with archive and password fields. It shows a previewing state, then a preview with folder, note, attachment, and skipped item counts.
- The preview confirmation asks for the password again and offers restore or cancel. It shows validation errors, an expired preview state, and a restoring state.
- The final result shows created and skipped counts, with the names and reasons for skipped items. Password fields clear after each action and passwords are not retained between preview and restore.
- Progress is shown by named phases only, not a percentage: preparing or validating, creating or restoring, and complete or failed. The export download name is `notes-backup.zip`.
- Throttled requests show a wait message and the retry time. Authentication failure uses the same message for an incorrect password and a damaged encrypted stream.

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `/settings/import-export` | `GET` | Signed in session | Page with export, import, and preview state | Authenticated and verified user | Redirect if not signed in or verified |
| `/settings/import-export/export` | `POST` | `password`, `password_confirmation` | `notes-backup.zip` download or validation error | Authenticated and verified user | 422 invalid password or confirmation, 413 size exceeded, 429 rate limited |
| `/settings/import-export/preview` | `POST` | `archive`, `password` | `preview_token`, UTC `expires_at`, folder, note, attachment, reused folder, and skipped counts | Authenticated and verified user | 400 invalid archive, 413 size exceeded, 422 authentication failed, 429 rate limited |
| `/settings/import-export/import` | `POST` | `preview_token`, `password` | Created, reused, and skipped counts by entity type; `skipped_items` with `entity_type`, `display_name`, and `reason_code` | Authenticated and verified user | 400 invalid or expired token, 409 invalid payload, 413 size exceeded, 422 authentication failed, 429 rate limited |
| `/settings/import-export/cancel` | `POST` | `preview_token` | Stage removed and user returned to the settings page | Authenticated and verified user | 404 missing or expired preview |

**Value sourcing**:

| Action | Value produced or displayed | Source |
|---|---|---|
| Settings page | Export and import sections and current flow state | This spec's Screens and states; validation and processing state from the active action |
| Export action | Archive size and download | Generated outer ZIP containing the encrypted inner ZIP |
| Export failure | Validation, archive size, or unreadable attachment message | Request validation or the specific export check that failed |
| Preview summary | Folder, note, attachment, reused folder, and skipped counts | Archive manifest, user scoped database, and duplicate handling rules above |
| Preview state | Expiry time | Preview token creation timestamp and the configured 15 minute lifetime |
| Restore confirmation | Password field | User input required again after preview; it is not read from stored state |
| Import decision | Whether a note already exists | `portable_id` lookup in the current user's notes |
| Import decision | Whether a folder already exists | `portable_id` lookup, then exact normalized name fallback within the current user |
| Restore note | Note title and HTML content | Data inside the encrypted package, sanitized before save |
| Restore attachment | Original name, MIME type, size, file content | Attachment payload inside the encrypted package and stored file path in the user's private disk |
| Download response | File download | Staged encrypted archive on the private disk |
| Import result | Created, reused, and skipped counts by entity type, skipped names, and reason codes | The restore operation's per item outcomes and stable reason codes above |
| Import failure | Invalid item display names and reasons, safe authentication message, size or expiry message | The specific validation, authentication, size, or token check that failed; unsafe archive paths are never displayed |
| Throttled action | Retry time and wait message | The shared per account rate limiter and its `Retry-After` value |

**Key invariants**:
- A password is never stored in the database, cache, or session.
- Passwords are handled only for the current export, preview, or restore request, and are not retained in component state or logs.
- Every imported record is assigned to the signed in user account.
- `portable_id` is unique within one user account and is used to detect repeated imports.
- A note is never overwritten if the same portable ID exists for the user.
- Attachments are never written outside that user's private note attachments directory.
- Export only succeeds when the archive stays under 100 MB and expanded content stays under 500 MB.
- The outer ZIP contains only the format description and encrypted payload; note metadata, attachment names, and attachment bytes are encrypted inside the payload.
- An archive is only considered valid after the encrypted payload is successfully authenticated and exactly one final secretstream tag is verified with no trailing data.
- Password derivation uses the exact supported Argon2id profile in the format description; user supplied derivation parameters are never run without validation.

**Security model**:
- All export and import actions require the signed in and verified user.
- The entire inner ZIP is encrypted before download, including metadata and attachment bytes. Only the format version and bounded key derivation metadata are visible in the outer ZIP.
- Passwords are request inputs only. They are never persisted or included in logs, and the password must be entered again before restore.
- Password derived keys and decrypted staging files are removed when the operation completes or fails.
- Imported records are always assigned to the signed in user. An archive grants no access to its source account or records.
- The feature does not add public APIs or cross account sharing. The account owner remains the only person who can access their backup, preview, or restore path.
- The app sanitizes HTML on both import and export so stale note content cannot reintroduce unsupported or unsafe markup.

**Configuration required**:
- `config('import_export.preview_ttl_minutes')`: fixed at 15 minutes.
- `config('import_export.max_archive_bytes')`: fixed at 104857600 bytes.
- `config('import_export.max_expanded_bytes')`: fixed at 524288000 bytes.
- `config('import_export.max_manifest_bytes')`: fixed at 16777216 bytes.
- `config('import_export.max_records')`: fixed at 100000 total records.
- `config('import_export.rate_limit_per_minute')`: fixed at 5 shared export, preview, and import attempts per account.
- Purifier application configuration: the tag, attribute, CSS property, and URL allowlists defined above.

These are fixed application settings for format version 1, not environment overrides. The importer accepts only the defined algorithms and parameter values.

**Critical test scenarios** (each maps to an acceptance criterion in ## Requirements):
- Happy path: a valid archive with notes, folders, and attachments previews and restores into the signed in account, verifies **AC-1**, **AC-2**, **AC-5**, **AC-6**, **AC-7**, **AC-12**.
- Encryption: note metadata and attachment bytes are unreadable in the outer ZIP, and tampering or a missing final authentication tag prevents writes, verifies **AC-1**, **AC-2**, **AC-3**.
- Archive safety: unexpected entries, duplicate names, traversal paths, and symlinks are rejected before extraction, verifies **AC-3**.
- Atomic restore: invalid files and database or storage failures leave no records or attachment files from the attempted restore, verifies **AC-3**, **AC-9**.
- Merge decisions: matching folders are reused, existing notes are not changed, unseen attachments are added, and repeated items have stable result reasons, verifies **AC-4**, **AC-6**, **AC-7**, **AC-13**.
- Failure case: failed authentication, invalid payload, or a missing attachment prevents import and leaves no partial writes, verifies **AC-3**, **AC-9**, **AC-11**, **AC-14**.
- Import safety: an item whose portable ID already exists in the account is reused or skipped according to the duplicate rules, and a duplicate ID inside the archive rejects it before writes, verifies **AC-3**, **AC-4**, **AC-13**.
- Sanitization: unsafe HTML or malicious URL schemes are stripped on import and export, verifies **AC-8**.
- Rate limit and expiry: preview and import attempts are throttled and the preview token expires or is cancelled cleanly, verifies **AC-10**.

## Build plan

1. Add the new `portable_id` columns and the per account unique indexes for folders, notes, and attachments, plus the data mapping needed for backup matching, satisfies **AC-1**, **AC-4**, **AC-6**, **AC-7**.
2. Add the export path and staged archive storage, encrypting the complete inner ZIP with the versioned Argon2id and secretstream profile, satisfies **AC-1**, **AC-2**, **AC-11**, **AC-14**.
3. Add the preview validation, bounded encryption profile checks, archive limits, shared rate limiting, and the 15 minute preview token expiry flow, satisfies **AC-3**, **AC-5**, **AC-10**, **AC-11**, **AC-12**.
4. Add the import pipeline with strict archive validation, payload authentication, atomic file and database writes, and additive restore logic, satisfies **AC-3**, **AC-4**, **AC-6**, **AC-7**, **AC-9**, **AC-11**, **AC-13**.
5. Add the sanitization layer and the attachment validation pass for imported content and files, satisfies **AC-8**, **AC-9**.
6. Add the import result summary and empty backup behavior, satisfies **AC-5**, **AC-12**, **AC-13**.
7. Add the profile settings UI with export and import states, password reentry, preview, restore, cancel, and result actions, satisfies **AC-1**, **AC-2**, **AC-5**, **AC-10**, **AC-12**, **AC-13**.

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
