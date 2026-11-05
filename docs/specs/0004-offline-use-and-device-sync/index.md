# 0004. Offline use and device sync

**Date**: 2026-11-05
**Status**: Proposed

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
- **AC-4**: The app is installable from the browser, opens from its icon, and loads its shell, local notes, and editor when the network is unavailable.
- **AC-5**: The app syncs automatically when it opens online and when the browser regains a connection, and a manual retry is always available. A status bar shows online, offline, or syncing, the last sync time, the pending change count, and a retry control.
- **AC-6**: A device pulls only changes after its stored revision through a server change log. Note deletes and restores appear as changes, folder deletes arrive as tombstones, and the client stores the new revision only after the whole batch applies.
- **AC-7**: When a queued note change uses a base version that is older than the server version, the server keeps its current note and saves the queued version as a normal note in All Notes titled `Conflict copy of <title>`. When the server note was deleted while the device edited it, the queued version is also saved as a conflict copy. No note content is silently overwritten or deleted.
- **AC-8**: A folder rename that loses to another device applies the latest server name, advances the folder version, and shows a notice. A folder deleted on another device wins; its local notes move to All Notes, and the pending local folder change is rejected without loss of note text.
- **AC-9**: When the server session has expired, local notes stay readable and queued changes stay in the outbox. The app asks the user to sign in again before sync, and resumes the queue after sign in without duplicating changes.
- **AC-10**: Every queued change carries a client change id. Replaying the same change after a timeout or retry applies it once, returns the same result, and creates at most one conflict copy. A failed batch stays queued and retries with backoff.
- **AC-11**: The sync endpoints return and accept only records owned by the signed in user. A forged note or folder portable id is rejected, and no other user's note content or change log is exposed.
- **AC-12**: Attachments stay online only. In the installed app, note text remains readable and editable offline, attachments show a clear message that a connection is needed, and online upload, download, and delete behavior is unchanged.
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
| `notes` | Existing `id`, `portable_id` (uuid), `user_id`, `folder_id`, `title`, `content`, `version`, timestamps, `deleted_at` | Reuse `portable_id` as the cross device key. Increment `version` on every note change. Add a unique index on `portable_id`; the migration aborts if existing values collide. |
| `folders` | Existing `id`, `portable_id`, `user_id`, `name`, `name_key`, timestamps | Add `version` (unsigned big integer, default 1). Add a unique index on `portable_id`; the migration aborts if existing values collide. |
| `sync_changes` | `id` (big integer primary key, the cursor), `user_id`, `entity_type` (`note` or `folder`), `entity_portable_id` (uuid), `action` (`created`, `updated`, `deleted`, `restored`), `client_change_id` (uuid, nullable), `conflict_note_portable_id` (uuid, nullable), `status` (`applied`, `conflict`, `rejected`, nullable), `error` (string, nullable), `changed_at` | Index `(user_id, id)`. Unique `(user_id, client_change_id)` when the id is present. Every note and folder change writes one row inside the same database transaction. |

Local IndexedDB tables through Dexie:

| Table | Fields | Rules |
|---|---|---|
| `notes` | `portable_id` (primary key), `folder_portable_id` (nullable), `title`, `content`, `version`, `deleted_at`, `created_at`, `updated_at`, `sync_state`, `last_synced_at` | `sync_state` is `synced`, `pending`, `syncing`, or `conflict`. Index `folder_portable_id`, `sync_state`, `updated_at`, `deleted_at`. |
| `folders` | `portable_id` (primary key), `name`, `name_key`, `version`, `updated_at`, `sync_state`, `last_synced_at` | Same sync states as notes. Index `sync_state`, `updated_at`. |
| `outbox` | `client_change_id` (primary key), `entity_type`, `entity_portable_id`, `action`, `payload` (JSON), `base_version`, `attempts`, `last_error`, `created_at` | One row per queued change. Push in creation order, one entity at a time when order matters. |
| `sync_meta` | `key` (primary key), `value` | Stores `last_revision`, `last_sync_at`, and the last seen session state. |

Relationships and constraints:
- A note belongs to one user and zero or one folder. A folder belongs to one user. The local note stores `folder_portable_id`, never the server integer id.
- A folder delete nulls the note folder on the server and queues the matching note changes. A conflict copy is a normal note in All Notes with `folder_id` null.
- A conflict copy takes the queued `client_change_id` as its `portable_id`, so a retry finds it instead of creating a second copy. Its title is `Conflict copy of <title>`, cut to 255 code points, and its content is the queued version.
- Attachments are not mirrored. Note HTML may still contain attachment references; the installed app shows the online only message instead of fetching them.

**State transitions**:
- Note sync state: `synced` → `pending` on a local edit → `syncing` while a batch is in flight → `synced` when the server accepts it, or `conflict` when the server keeps a newer version and creates a conflict copy. The client applies the server result and returns the note to `synced`.
- Folder sync state follows the same path. A rejected delete or rename applies the server folder state and returns the folder to `synced`.
- Outbox item: `queued` → `sending` → `done` or `failed`. A failed item stays queued with an incremented attempt count and a `last_error`; the next sync retries with backoff.

**API surface**:
| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `/sync` | GET | `since` (integer, required, 0 for a full pull), `limit` (integer, optional, default 200) | `revision`, `notes`, `folders`, `deleted_folder_portable_ids`, `has_more` | Session and verified email | 401 when the session is missing, 429 when rate limited |
| `/sync` | POST | `changes` (array, required, maximum 100), each with `client_change_id`, `entity_type`, `action`, `portable_id`, `base_version`, `payload` | `server_revision`, `results` (one per change with `status`, `note`, `folder`, `conflict_note`, `message`), `notes`, `folders`, `deleted_folder_portable_ids` | Session, verified email, CSRF token | 401, 422 for an invalid batch, 429 when rate limited |

Notes:
- The read path returns changed notes, including soft deleted notes, changed folders, and folder tombstones derived from change rows. The client loops while `has_more` is true.
- The write path applies note create, update, trash, restore, and folder create, rename, delete. It returns per change results in a 200 response so one bad change does not discard a whole batch.
- Both routes live in `routes/web.php` behind `auth` and `verified` because they use the existing session and CSRF protection. The installed app reads the CSRF token from a meta tag and sends it in `X-CSRF-TOKEN`.
- Rate limit both routes to 60 requests per minute per user.
- Sync runs only while the installed app is open. There is no background sync after the app closes.

**Value sourcing**:
| Action | Value produced or displayed | Source |
|---|---|---|
| Initial pull | `since` | `sync_meta.last_revision`, default 0 |
| Initial pull | `revision` after applying a batch | `sync_changes.id` maximum for the user, returned as `revision` |
| Sync status bar | online, offline, or syncing | Browser online events plus the current sync run state |
| Sync status bar | last sync time | `sync_meta.last_sync_at` |
| Sync status bar | pending count | Number of rows in the `outbox` table |
| Push a note change | `client_change_id` | UUID generated on the device when the change is queued |
| Push a note change | `base_version` | Local note `version` at the time of the edit |
| Push a note change | note payload | Local note title, content, `folder_portable_id`, and deleted state |
| Pull a page | `has_more` | Fetched change count compared with `limit` |
| Pull a page | `deleted_folder_portable_ids` | `sync_changes` rows with folder type and the deleted action |
| Push a change | result `status` | Server outcome stored on the matching `sync_changes` row |
| Push a change | `conflict_note` | Note created with `portable_id` equal to the queued `client_change_id` |
| Folder delete | notice text | `POST /sync` result message |
| Conflict copy | title | `Conflict copy of <title>`, cut to 255 code points |
| Conflict copy | content | Queued note content from the client change |
| Conflict copy | `portable_id` | The queued `client_change_id` |
| Folder delete | notes moved to All Notes | Server folder delete clears `notes.folder_id` and writes note changes |
| Session expired | local notes readable, sync blocked | A 401 from the sync API plus the local store |
| Install link | app URL | Named route for `/app` |

**Key invariants**:
- Every note and folder mutation writes exactly one `sync_changes` row in the same transaction as the mutation.
- The client never sends a server integer id; it sends `portable_id` values only.
- A note change with a stale `base_version` never overwrites or deletes the newer server note. It creates at most one conflict copy.
- A folder name stays unique per user through the existing `name_key` index. A folder delete frees the name; a recreated folder gets a new `portable_id`.
- The outbox is applied in order per entity. A change that fails stays queued; later changes to the same entity wait behind it.
- The local revision advances only after the entire pulled batch is written to IndexedDB.
- Attachments never enter the local store.

**Security model**:
- Every sync query is scoped to the signed in user through the session. Note and folder portable ids are looked up through the user's relations; a forged id returns 404 and never touches another user's data.
- The sync API uses the existing session and CSRF token. It adds no long lived API token and no local encryption. Local notes are protected by the device lock and browser profile, the same trust model as the current server session.
- Note content stays escaped HTML. The installed app renders it through Tiptap or a safe text path and never injects raw HTML.
- Sync routes are rate limited. Logs never include note content, titles, or portable ids.
- A session expiry blocks sync, not local reading. The app shows a sign in prompt and keeps the outbox.

**Configuration required**:
No new environment variables or credentials. The service worker needs a production build and HTTPS, or localhost during development.

**Critical test scenarios** (each maps to an acceptance criterion in `## Requirements`):
- Happy path: a new device pulls all notes and folders, edits a note offline, reconnects, and the note syncs once; verifies **AC-1**, **AC-2**, **AC-5**, **AC-6**, **AC-10**.
- Conflict case: two devices edit the same note offline and both sync; the later sync creates one conflict copy in All Notes and keeps the server note; verifies **AC-7**, **AC-10**.
- Folder case: one device renames a folder while another deletes it; the delete wins, notes move to All Notes, and the rename is rejected with a notice; verifies **AC-3**, **AC-8**.
- Session case: the session expires while offline; local notes stay readable, the queue remains, and sign in resumes sync; verifies **AC-9**.
- Auth case: user B calls the sync API with user A's note id and receives 404 with no data; verifies **AC-11**.
- Attachment case: an offline note with an attachment keeps its text and shows the connection message; online upload, download, and delete still pass; verifies **AC-12**.
- Update case: a new service worker is installed while the outbox has pending changes; the changes survive and sync after activation; verifies **AC-14**.
- Online regression: the existing Livewire notes tests, quick capture tests, and attachment tests still pass; verifies **AC-13**.

## Build plan

1. Add one migration for `sync_changes`, `folders.version`, and unique `portable_id` indexes, with collision checks that abort cleanly; add the change log writes to note and folder mutations inside transactions, satisfies **AC-6**, **AC-11**.
2. Add the read path `GET /sync` with cursor pagination, note and folder changes, folder tombstones, session auth, and rate limiting; cover it with Pest tests, satisfies **AC-1**, **AC-6**, **AC-11**.
3. Add the write path `POST /sync` for batched note and folder changes, client change ids, stale version handling, conflict copies, folder rules, and per change results; cover it with Pest tests, satisfies **AC-2**, **AC-7**, **AC-8**, **AC-10**.
4. Add the PWA shell at `/app`: manifest, icons, service worker, offline fallback, and the Vite entry, satisfies **AC-4**.
5. Add the Dexie store and the pull engine: tables, initial full pull, cursor storage, and a local notes list with folders and trash; the list opens offline, satisfies **AC-1**, **AC-6**.
6. Add offline note editing with Tiptap: create, edit, trash, and restore write to the local store and the outbox; add the status bar, pending count, automatic sync, manual retry, and backoff, satisfies **AC-2**, **AC-5**, **AC-10**.
7. Add offline folders and note folder assignment, then the conflict copy flow and folder conflict notices, satisfies **AC-3**, **AC-7**, **AC-8**.
8. Add session expiry handling and the offline attachment message, satisfies **AC-9**, **AC-12**.
9. Add the service worker update flow that keeps IndexedDB and the outbox intact, satisfies **AC-14**.
10. Add the install entry on the Livewire notes page and run the existing online regression tests, satisfies **AC-13**.

## Consequences

**Positive**:
- Notes and folders stay usable with no connection, including on a phone.
- One server change log gives every device a reliable cursor; deletes and folder changes are not guessed from timestamps.
- Conflict copies protect note text when devices disagree, which fits a private notes app better than a silent merge.
- The existing Livewire page is untouched, so the current browser workflow and its tests keep working.
- No new hosted service, no API token, and no new environment variables.

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

- [ ] Decide whether to add Vitest and a fake IndexedDB library for the Dexie sync engine. This is a new dependency and needs approval; without it, the client is verified by the manual checklist only.
- [ ] Add a pruning policy for `sync_changes` once the device set is known, so the table does not grow without limit.
- [ ] Consider offline attachments and background sync as later extensions after the text sync is stable.
- [ ] Add a sync health metric or log line that counts failed pushes, without note content.
- [ ] Add a way to review and clear old conflict copies in bulk if they become common.

## Rationale

Reasoning and options: see [rationale.md](rationale.md).
