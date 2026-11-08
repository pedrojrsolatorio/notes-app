# 0004. Offline use and device sync

**Date**: 2026-11-05
**Status**: In Progress

## Summary

Give the notes app an installable view that keeps every note and folder on the device, works without a connection, and syncs changes back to Laravel when the connection returns. The browser stores notes in IndexedDB through Dexie, sends queued changes through a small Laravel sync API, and keeps both versions when the same note changed on another device. The existing Livewire notes page stays as the browser experience; the installed app gets its own client view built on the same data and the same Tiptap editor.

## Requirements

**User stories**:
- As the owner of these notes, I want my notes and folders on my device so that I can read and edit them on a plane or with a weak connection.
- As someone who edits on two devices, I want my changes to reach the other device without losing either version so that I can trust the app with quick thoughts.
- As someone who installs the app on a phone, I want a fast view that opens offline and tells me when my changes have synced.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: On the first online visit to the installed app, every note and folder for the signed in user is copied into the local store, including the last sync revision. After that copy, the notes list, folders, and trash open without a network request.
- **AC-2**: A note created or edited while offline is saved in the local store immediately, survives a reload while still offline, appears in the list, and is searchable by title and body. Its pending state is visible until the server accepts it.
- **AC-3**: Folder create, rename, and delete, note folder assignment, trash, and restore work offline and queue in order. Deleting a folder offline leaves its notes in All Notes, matching the current online rule.
- **AC-4**: The app is installable from the browser, opens from its icon, and loads its shell, local notes, and editor when the network is unavailable. The `/app` shell is public and probes the session from the client; after a session expiry the shell still opens the last user's local store.
- **AC-5**: The app syncs automatically when it opens online and when the browser regains a connection, and a manual retry is always available. A status bar shows online, offline, or syncing, the last sync time, the pending change count, and a retry control.
- **AC-6**: A device pulls only changes after its stored revision through a server change log. Note deletes and restores appear as changes, folder deletes arrive as tombstones, and the client stores the new revision only after the whole batch applies.
- **AC-6b**: A `since` of 0 is a full snapshot of the user's current notes, including trashed notes, and current folders, captured at a server revision read before the snapshot. The client stores the snapshot and that revision. The change log is never pruned below the lowest revision that a live device may still hold; a device whose cursor is older than the retained history must resync from 0.
- **AC-6c**: The pull cursor is the id of the last change row returned; when a page is empty it stays at the requested `since`. `has_more` counts remaining change rows, not distinct notes. Each returned note carries its full content, version, folder portable id, and deleted state.
- **AC-7**: When a queued note change uses a base version that is older than the server version and the title or body changed on both sides, the server keeps its current note and saves the queued version as a normal note in All Notes titled `Conflict copy of <title>`. When the server note was deleted while the device edited it, the queued version is also saved as a conflict copy. A metadata only change, such as a folder move or a trash or restore, applies without a conflict copy. No note content is silently overwritten or deleted.
- **AC-7b**: A permanent delete writes a note tombstone a device can apply, so a device that already has the note removes it. A conflict copy writes its own created change row with no client change id, so other devices receive it.
- **AC-8**: A folder rename with a stale base version is rejected; the client applies the server name and version and shows a notice. A folder deleted on another device always wins; its local notes move to All Notes, and the pending local folder change is rejected without loss of note text.
- **AC-9**: When the server session has expired, local notes stay readable and queued changes stay in the outbox. The app asks the user to sign in again before sync, and resumes the queue after sign in without duplicating changes. The sync API is called with JSON headers, and a login redirect, a 401, or a 419 from a stale CSRF token all count as needs sign in.
- **AC-9b**: The local store is scoped by user. Signing in as a different user on the same browser never shows the previous user's local notes, and the outbox for the previous user is preserved but not sent as the new user.
- **AC-10**: Every queued change carries a client change id. Replaying the same change after a timeout or retry applies it once, returns the same result, and creates at most one conflict copy. A failed batch stays queued and retries with backoff.
- **AC-10b**: Pending changes to the same entity coalesce into one outbox row with the latest payload and the earliest base version. Multiple offline saves to one note produce one push, not one conflict copy per save. The local version stays at the last server acknowledged version until a push succeeds.
- **AC-10c**: A change the server finally rejects, such as a duplicate folder name or an invalid title, is terminal: the outbox row is dropped, the message is shown, and local data is kept. A temporary failure such as a network error stays retryable.
- **AC-11**: The sync endpoints return and accept only records owned by the signed in user. A forged note or folder portable id is rejected, and no other user's note content or change log is exposed.
- **AC-12**: Attachments stay online only. In the installed app, note text remains readable and editable offline, attachments show a clear message that a connection is needed, and online upload, download, and delete behavior is unchanged.
- **AC-12b**: The server sends a minimal per note attachment manifest with each note, holding the attachment portable id, name, mime type, and size without the file bytes. The offline UI uses it to render the attachment placeholder and the connection message. No attachment file is stored in the local store.
- **AC-13**: The existing Livewire notes page keeps its current behavior when online, including Tiptap editing, search, sort, folders, trash, quick capture, attachments, validation, and toasts. The installed app adds an install entry without replacing that page.
- **AC-14**: A new service worker version activates without deleting the local store or the outbox, and the user can keep working while an update waits.

## Decision

**Chosen option**: Option 2: Installable PWA with a Dexie sync client and a Laravel change feed.

The installed app is a dedicated client view backed by IndexedDB and a small Laravel sync API. The existing Livewire page remains for browser use, and the two views share the same note rules.

**Implementation skills**: `laravel-best-practices` (`pedrojrsolatorio/notes-app`, `.agents/skills/laravel-best-practices/`) · `livewire-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/livewire-development/`) · `tailwindcss-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/tailwindcss-development/`) · `fortify-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/fortify-development/`) · `testing-best-practices` (`pedrojrsolatorio/notes-app`, `.agents/skills/testing-best-practices/`)

## Feature design

**Data model sketch**:

Server tables:

| Table | Fields | Rules |
|---|---|---|
| `notes` | Existing `id`, `portable_id` (uuid), `user_id`, `folder_id`, `title`, `content`, `version`, timestamps, `deleted_at` | Reuse `portable_id` as the cross device key. It already has the unique index `(user_id, portable_id)` from the portable id migration. Increment `version` on every note change. |
| `folders` | Existing `id`, `portable_id`, `user_id`, `name`, `name_key`, timestamps | Add `version` (unsigned big integer, default 1). The unique index `(user_id, portable_id)` already exists. |
| `sync_changes` | `id` (big integer primary key, the cursor), `user_id`, `entity_type` (`note` or `folder`), `entity_portable_id` (uuid), `action` (`created`, `updated`, `deleted`, `restored`, `purged`), `client_change_id` (uuid, nullable), `conflict_note_portable_id` (uuid, nullable), `status` (`applied`, `conflict`, `rejected`, nullable), `error` (string, nullable), `changed_at` | Index `(user_id, id)`. Unique `(user_id, client_change_id)` when the id is present. Every note and folder change writes one row inside the same database transaction. The consume writer for permanent deletes uses the `purged` action so a device removes its local copy. |

Local IndexedDB tables through Dexie:

| Table | Fields | Rules |
|---|---|---|
| `notes` | `portable_id` (primary key), `user_id`, `folder_portable_id` (nullable), `title`, `content`, `version`, `deleted_at`, `created_at`, `updated_at`, `sync_state`, `last_synced_at` | `sync_state` is `synced`, `pending`, `syncing`, or `conflict`. Index `(user_id, folder_portable_id)`, `(user_id, sync_state)`, `(user_id, updated_at)`, `(user_id, deleted_at)`. |
| `folders` | `portable_id` (primary key), `user_id`, `name`, `name_key`, `version`, `updated_at`, `sync_state`, `last_synced_at` | Same sync states as notes. Index `(user_id, sync_state)`, `(user_id, updated_at)`. |
| `outbox` | `client_change_id` (primary key), `user_id`, `entity_type`, `entity_portable_id`, `action`, `payload` (JSON), `base_version`, `status` (`queued`, `sending`, `failed`, `rejected`), `attempts`, `last_error`, `created_at` | One row per queued change, coalesced per entity with the latest payload and the earliest base version. Creates use `base_version` 0. Push in this order inside one batch: folder creates, note changes, folder renames, folder deletes. |
| `sync_meta` | `key` (primary key), `value` | Stores `last_revision`, `last_sync_at`, and the last seen session state per user, for example `last_revision:<user_id>`. |

Relationships and constraints:
- A note belongs to one user and zero or one folder. A folder belongs to one user. The local note stores `folder_portable_id`, never the server integer id. Every local row carries `user_id` and queries filter by the current user.
- Databases are opened with a name that includes the user id, so a second user on the same browser gets a separate store. A sign out keeps the signed out user's store on the device but does not send its outbox as the new user.
- A folder delete nulls the note folder on the server and queues the matching note changes. A conflict copy is a normal note in All Notes with `folder_id` null.
- A conflict copy takes the queued `client_change_id` as its `portable_id`, so a retry finds it instead of creating a second copy. Its title is `Conflict copy of <title>`, cut to 255 code points, and its content is the queued version. The copy writes a `created` change row with a null `client_change_id`; the original client change row keeps the `conflict_note_portable_id`.
- An updated note version bumps on content, title, folder move, trash, and restore, but not on an attachment only change.
- Attachments are not mirrored as files. The sync API sends a manifest of attachment portable ids with each note; the installed app shows the online only message instead of fetching bytes.

**State transitions**:
- Note sync state: `synced` → `pending` on a local edit → `syncing` while a batch is in flight → `synced` when the server accepts it, or `conflict` when the server keeps a newer version and creates a conflict copy. The client applies the server result and returns the note to `synced`.
- Folder sync state follows the same path. A rejected delete or rename applies the server folder state and returns the folder to `synced`.
- Outbox item: `queued` → `sending` → `done` or `failed`, or `rejected` when the server finally refuses the change. A failed item stays queued with an incremented attempt count and a `last_error`; the next sync retries with backoff. A rejected item is dropped from the outbox and its message is shown; local data is kept.
- The status bar shows `online`, `offline`, `syncing`, `needs sign in`, and `error`.

**API surface**:
| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `/sync` | GET | `since` (integer, required, 0 for a full snapshot that ignores `limit`), `limit` (integer, optional, default 200, ignored when `since` is 0) | `revision`, `notes`, `folders`, `deleted_folder_portable_ids`, `deleted_note_portable_ids`, `has_more` | Session and verified email, with JSON headers | 410 when `since` is older than the retained change history, 401, 302, or 419 when the session or CSRF token is missing or stale, 429 when rate limited |
| `/sync` | POST | `changes` (array, required, maximum 100), each with `client_change_id`, `entity_type`, `action`, `portable_id`, `base_version`, `payload` | `server_revision`, `results` (one per change with `status`, `note`, `folder`, `conflict_note`, `message`), `notes`, `folders`, `deleted_folder_portable_ids`, `deleted_note_portable_ids` | Session, verified email, CSRF token, with JSON headers | 401, 302, or 419 when the session is missing or stale, 422 for an invalid batch, 429 when rate limited |

Notes:
- The read path returns changed notes, including soft deleted notes, changed folders, folder tombstones, and purged note tombstones derived from change rows. Each note carries full content, version, folder portable id, deleted state, and its attachment manifest. The client loops while `has_more` is true. `revision` is the id of the last change row returned, or the requested `since` when the page is empty.
- A `since` of 0 is a full snapshot of current notes, including trashed notes, and current folders. The snapshot ignores `limit`. The server reads the revision once before the snapshot and returns it as `revision`. The client stores the snapshot and that revision. If `since` is older than the retained change history, the server returns 410 with the minimum retained revision, and the client retries with `since` 0.
- The write path applies note create, update, trash, restore, and permanent delete, plus folder create, rename, delete. It returns per change results in a 200 response so one bad change does not discard a whole batch. A forged portable id is a per change rejection, not a batch failure. The response returns only the states touched by this batch, not a pull since a cursor. `server_revision` is the maximum `sync_changes.id` after the batch. The client applies those touched states, then pulls until `has_more` is false.
- Both routes live in `routes/web.php` behind `auth` and `verified` because they use the existing session and CSRF protection. The installed app sends JSON headers and reads the CSRF token from a meta tag, sending it in `X-CSRF-TOKEN`. A 302 redirect and a 401 both mean needs sign in. A 419 triggers one CSRF refresh from the page and one retry before it counts as needs sign in.
- A batch wide authentication failure is a single 401 response to the whole request. A forged entity inside a batch is a per change rejection in the 200 response, never a batch failure.
- The `/app` shell route is public. It serves the static client, which probes the session from the client and opens the per user database named in `last_user_id`. Sync routes stay behind `auth` and `verified`.
- Rate limit both routes to 60 requests per minute per user.
- Sync runs only while the installed app is open. There is no background sync after the app closes.
- The app asks the browser for persistent storage. Only one tab syncs at a time, using a browser lock when available. In flight sync state is reset on application start.
- `/sync` and attachment endpoints are never cached by the service worker.
- An attachment added or removed online writes an `updated` change row without bumping the note version, so other devices refresh the note manifest on their next pull.

**Value sourcing**:
| Action | Value produced or displayed | Source |
|---|---|---|
| Initial pull | `since` | `sync_meta.last_revision` for the current user, default 0 |
| Initial pull | `revision` after applying a batch | Id of the last change row returned, or the requested `since` when the page is empty |
| Sync status bar | online, offline, or syncing | Browser online events plus the current sync run state |
| Sync status bar | last sync time | `sync_meta.last_sync_at` |
| Sync status bar | pending count | Number of rows in the `outbox` table |
| Push a note change | `client_change_id` | UUID generated on the device when the change is queued |
| Push a note create | `portable_id` | UUID generated on the device when the note is created offline; the server inserts with that value |
| Push a note change | `base_version` | Local note `version` at the time of the edit |
| Push a note change | note payload | Local note title, content, `folder_portable_id`, and deleted state |
| Pull a page | `has_more` | Fetched change count compared with `limit` |
| Pull a page | `deleted_folder_portable_ids` | `sync_changes` rows with folder type and the deleted action |
| Push a change | result `status` | Server outcome stored on the matching `sync_changes` row |
| Push a change | `conflict_note` | Note created with `portable_id` equal to the queued `client_change_id` |
| Pull a page | `deleted_note_portable_ids` | `sync_changes` rows with note type and the purged action |
| Note manifest | attachment portable id, name, mime, and size | User scoped `note_attachments` rows for the note, without file bytes |
| Folder delete | notice text | `POST /sync` result message |
| Conflict copy | title | `Conflict copy of <title>`, cut to 255 code points |
| Conflict copy | content | Queued note content from the client change |
| Conflict copy | `portable_id` | The queued `client_change_id` |
| Folder delete | notes moved to All Notes | Server folder delete clears `notes.folder_id` and writes note changes |
| Session expired | local notes readable, sync blocked | A 401 from the sync API plus the local store |
| Local search result | plain text to match | Title plus note content stripped with the same decode and tag strip rule as the Livewire preview, matched case insensitively |
| Install link | app URL | Named route for `/app` |

**Key invariants**:
- Every note and folder mutation writes exactly one `sync_changes` row in the same transaction as the mutation. This covers the Livewire actions in the notes component, backup restore, and the `nullOnDelete` cascade, which writes one updated row per affected note.
- The write path increments `notes.version` on content, title, folder move, trash, restore, and permanent delete. The read path never decides success by a bare timestamp.
- The client never sends a server integer id; it sends `portable_id` values only.
- A note change with a stale `base_version` never overwrites or deletes the newer server note. It creates at most one conflict copy.
- A folder name stays unique per user through the existing `name_key` index. A folder delete frees the name; a recreated folder gets a new `portable_id`.
- The outbox is applied in order per entity. A change that fails stays queued; later changes to the same entity wait behind it. Pending changes to the same entity coalesce into one row with the latest payload and the earliest base version.
- The local revision advances only after the entire pulled batch is written to IndexedDB.
- A rejected change is terminal for that outbox row. Its local data is kept and the message is shown.
- Attachment files never enter the local store. Only the manifest does.
- Local rows are scoped by `user_id`, and a second user's store never receives the first user's outbox.

**Security model**:
- Every sync query is scoped to the signed in user through the session. Note and folder portable ids are looked up through the user's relations; a forged id is rejected as a per change rejection and never touches another user's data.
- The sync API uses the existing session and CSRF token. It adds no long lived API token and no local encryption. Local notes are protected by the device lock and browser profile, the same trust model as the current server session.
- Note content stays escaped HTML. The installed app renders it through Tiptap or a safe text path and never injects raw HTML.
- Sync routes are rate limited. Logs never include note content, titles, or portable ids.
- A session expiry blocks sync, not local reading. The app shows a sign in prompt and keeps the outbox.

**Configuration required**:
Dexie is a new JavaScript dependency for the installed app. It needs approval before it is added. No new environment variables or credentials. The service worker needs a production build and HTTPS, or localhost during development. Storage persistence and a browser lock are browser capabilities, not environment variables.

The offline view renders the notes list, search, folder filter, trash, Tiptap editor, and quick capture from the local store. Search is a case insensitive substring match over the title plus the note content stripped with the same decode and tag strip rule as the Livewire preview. Results sort by `updated_at` descending; trashed notes appear only in the trash view. The offline view shares the Tiptap editor with a new Dexie backed component.

**Critical test scenarios** (each maps to an acceptance criterion in `## Requirements`):
- Happy path: a new device pulls all notes and folders, edits a note offline, reconnects, and the note syncs once; verifies **AC-1**, **AC-2**, **AC-5**, **AC-6**, **AC-10**.
- Pagination case: a device pulls more pages than one limit and stores the last returned row as its cursor; an empty page leaves the cursor unchanged; verifies **AC-6**, **AC-6c**.
- Full pull case: after the change log is pruned, a new device uses `since` 0 and receives a current snapshot at the revision read before it; verifies **AC-6b**.
- Coalescing case: three offline saves to one note produce one push and no conflict copy; verifies **AC-10b**.
- Rejected case: a duplicate folder rename is dropped from the outbox, shows its message, and keeps local data; verifies **AC-10c**.
- Conflict case: two devices edit the same note offline and both sync; the later sync creates one conflict copy in All Notes and keeps the server note; verifies **AC-7**, **AC-10**.
- Purge case: a permanent delete on one device removes the note on a device that already had it, and the conflict copy created by another device also appears; verifies **AC-7b**.
- Folder case: one device renames a folder with a stale base while another renames it first, and the stale rename is rejected with a notice; then one device deletes a folder while another renames it, and the delete wins, notes move to All Notes, and the rename is rejected with a notice; verifies **AC-3**, **AC-8**.
- Metadata case: a note is trashed on one device while its title is edited on another; the trash applies without creating a conflict copy; verifies **AC-7**.
- Session case: the session expires while offline; local notes stay readable, the queue remains, and sign in resumes sync; verifies **AC-9**.
- User scope case: user B signs in on a browser that holds user A's store and never sees user A's notes or outbox; verifies **AC-9b**, **AC-11**.
- Auth case: user B calls the sync API with user A's note id and receives a per change rejection with no data; verifies **AC-11**.
- Attachment case: an offline note with an attachment shows its manifest entry and the connection message; online upload, download, and delete still pass; verifies **AC-12**, **AC-12b**.
- Update case: a new service worker is installed while the outbox has pending changes; the changes survive and sync after activation; verifies **AC-14**.
- Online regression: the existing Livewire notes tests, quick capture tests, backup restore tests, and attachment tests still pass; verifies **AC-13**.

## Build plan

1. Add one migration for `sync_changes` and `folders.version`, reusing the existing unique `(user_id, portable_id)` indexes; add the change log writes to every note and folder mutation path (Livewire note actions, folder deletes, the backup restore service, and the `nullOnDelete` cascade) inside transactions, satisfies **AC-6**, **AC-11**, **AC-13**.
2. Add the read path `GET /sync` with a change row cursor, `since` 0 snapshot semantics, pagination, folder and purged note tombstones, the attachment manifest, session auth, and rate limiting; cover it with Pest tests, satisfies **AC-1**, **AC-6**, **AC-6b**, **AC-6c**, **AC-11**, **AC-12b**.
3. Add the write path `POST /sync` for batched note and folder changes, client change ids, stale version handling, conflict copies, folder rules, final rejections, and per change results; cover it with Pest tests, satisfies **AC-2**, **AC-7**, **AC-7b**, **AC-8**, **AC-10**, **AC-10c**.
4. Add the PWA shell at `/app`: manifest, icons, service worker, offline fallback, and the Vite entry, satisfies **AC-4**.
5. Add the Dexie store and the pull engine: per user database name, per user tables and cursor, initial full pull, storage persistence, a single syncing tab, and a local notes list with folders and trash; the list opens offline, satisfies **AC-1**, **AC-6**, **AC-9b**.
6. Add offline note editing with Tiptap: create, edit, trash, and restore write to the local store and coalesce in the outbox; add the status bar with needs sign in and error states, pending count, automatic sync, manual retry, backoff, and in flight reset on start, satisfies **AC-2**, **AC-5**, **AC-10**, **AC-10b**, **AC-10c**.
7. Add offline folders and note folder assignment, then the conflict copy flow and folder conflict notices, satisfies **AC-3**, **AC-7**, **AC-8**.
8. Add session expiry handling and the offline attachment placeholder, satisfies **AC-9**, **AC-12**, **AC-12b**.
9. Add the service worker update flow that keeps IndexedDB and the outbox intact, satisfies **AC-14**.
10. Add the install entry on the Livewire notes page and run the existing online regression tests, satisfies **AC-13**.

## Consequences

**Positive**:
- Notes and folders stay usable with no connection, including on a phone.
- One server change log gives every device a reliable cursor; deletes and folder changes are not guessed from timestamps.
- Conflict copies protect note text when devices disagree, which fits a private notes app better than a silent merge.
- The existing Livewire page is untouched, so the current browser workflow and its tests keep working.
- No new hosted service, no API token, and no new environment variables. Dexie is the one new JavaScript dependency.
- The per note attachment manifest lets the offline UI show a truthful placeholder without caching file bytes.

**Negative / tradeoffs**:
- The installed app is a second notes interface. It shares data and rules, but it has its own list, editor, search, and error states to maintain.
- Local notes are not encrypted beyond the device lock and browser profile. A shared unlocked device exposes them.
- Sync runs only while the app is open; a user who closes the app will not receive changes until the next open.
- Conflict copies can accumulate and are not merged automatically. The user must review and delete the extra note.
- The `sync_changes` table grows with every edit. It needs a retention or pruning policy later.
- Attachments are not available offline, so a note with files is only partly usable on a plane.
- A service worker and a second client add browser cache and update failure modes that server rendered pages do not have.

**Neutral**:
- A new `/app` route and a new JavaScript entry are added; the existing `/notes` route, Livewire component, and Tiptap wrapper stay.
- The `notes.version` column moves from unused to load bearing. Folder versions are new.
- Server tests cover the sync API. The browser sync engine has no JavaScript test runner today; it is covered by the manual verify file unless a runner is approved.

## Follow-up

- [ ] Add a pruning policy for `sync_changes` that never drops below the lowest revision a live device may still hold; a device older than the retained history must resync from 0.
- [ ] Add Vitest and a fake IndexedDB library for the Dexie sync engine, with approval for the new dependencies, so outbox coalescing, pagination, and per user scoping have automated tests.
- [ ] Decide how duplicate folder creation offline should settle, for example by rebinding local notes to the existing folder. The current rule rejects the duplicate and keeps local data.
- [ ] Consider offline attachments and background sync as later extensions after the text sync is stable.
- [ ] Add a sync health metric or log line that counts failed pushes, without note content.
- [ ] Add a way to review and clear old conflict copies in bulk if they become common.

## Rationale

Reasoning and options: see [rationale.md](rationale.md).
