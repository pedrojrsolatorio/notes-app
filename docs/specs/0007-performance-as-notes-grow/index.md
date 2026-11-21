# 0007. Performance as notes grow

**Date**: 2026-11-19
**Status**: In Progress

## Summary

This decision keeps the notes list comfortable at 10,000 notes by paging the list 50 rows at a time and adding the indexes those pages need. Both the Livewire notes page and the installed offline app use the same opaque page cursor, so a page change cannot skip or repeat notes when the collection changes. The work adds no new table and no new dependency. It reuses the current search, sort, folder, trash, empty, error, and status rules.

## Requirements

**User stories**:

1. As a note owner, I want browse and search to stay fast as my collection grows so that finding a note still feels immediate.
2. As someone loading a long list, I want to keep the rows I already have and click Load more so that I do not lose my place.
3. As someone using the installed app, I want the same paging and search behavior offline so that the two views stay predictable.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):

1. **AC-1**: With 10,000 notes for one user in SQLite, the measured `/notes` paths, first page, newest Load more, search, folder, Trash, title A to Z, and title Z to A, each return 50 rows at a time and stay under 500 ms at the 95th percentile, measured by a seeded benchmark after a warm up. The end of list count read is included in the measured path.
2. **AC-2**: Search, All Notes, folder views, and Trash use the same paged read on `/notes` and the same paged local read in `/app`. A page boundary is a versioned base64url value naming sort, filter, sort key, and anchor. The server anchor is the note public id, mapped once to the owner scoped note row, and the local anchor is the Dexie portable id. It is never an offset, so inserts and deletes between loads do not cause duplicate or missing rows.
3. **AC-3**: Newest, oldest, title A to Z, and title Z to A sorts remain available. Newest orders by `updated_at desc, id desc`. Oldest orders by `updated_at asc, id asc`. Title A to Z orders by `title_key asc, id asc`. Title Z to A orders by `title_key desc, id desc`. `title_key` is derived from `title` with the same algorithm as folder `name_key`: trim Unicode whitespace and Unicode line and tab characters at both ends, then lowercase as UTF-8. It is stored as 510 characters for normalization headroom, while `title` validation stays at 255 characters.
4. **AC-4**: Load more appends the next 50 rows, keeps existing rows, moves the cursor, and announces the newly loaded row count once through the existing status region. Focus stays on Load more. When no rows remain, the control disappears, focus moves to the list heading, and the total matched count is announced once from a count on the same owner scoped and filtered query. A failed load keeps rows and the cursor unchanged, announces the failure once, and allows retry.
5. **AC-5**: An explicit sort or filter commit resets to the first page, clears the old cursor, and moves focus to the list heading. Live debounced search input does not move focus. When the selected note no longer exists in the full current filter, confirmed by an owner scoped existence check, the editor clears and focus moves to the list heading.
6. **AC-6**: `/app` uses the same 50 row page, the same opaque cursor rule, keeps old rows while loading, and reuses the current empty and error messages. A sync completion that advances the local revision resets the local list to the first page once per sync run, using the revision as the dedupe key, and announces the refresh once.
7. **AC-7**: The target data model adds nullable `notes.title_key` as a string of 510 characters, adds read indexes for user plus time, user plus folder, user plus trash, and user plus title key, and backfills existing notes in chunks of 500 with deterministic values. No new tables, relationships, or uniqueness rules are added.
8. **AC-8**: Every read stays scoped to the signed in user. The cursor is plain validated state, not locked, so invalid and cross user cursors can be exercised. Validation requires the cursor sort and filter to match current state and the anchor to exist in the owner scoped filtered query. Invalid input is discarded and replaced with a first page load plus a status message. No note title, note content, title key, or cursor value is written to logs or status text.
9. **AC-9**: List rows read only the first 2,000 raw characters of `content` into PHP as a preview source, then reuse the current tag stripping and 120 character display limit. This bounds data transferred to the application. SQLite may still read the content column when a search scans note bodies.
10. **AC-10**: The 500 ms target is recorded in the spec and benchmark test with no new environment setting. The benchmark seeds 10,000 notes, warms up, then takes 100 timed reads per measured path. The 95th percentile uses nearest rank and misses fail verification. The benchmark uses a PHPUnit group excluded from CI timing, while CI runs query count and shape checks. The dev machine runs the benchmark explicitly.
11. **AC-11**: Existing note, folder, search, sort, trash, quick capture, reminder, attachment, backup, and sync behavior still passes its focused and full regression tests. Reminder fields stay out of the offline sync payload.
12. **AC-12**: With 10,000 local notes in Dexie, the measured `/app` paths, first page, newest Load more, search, folder, Trash, title A to Z, and title Z to A, stay under 500 ms at the 95th percentile in a browser timing check on the dev machine after a warm up. CI checks local index shape instead of wall clock time.

## Decision

**Chosen option**: Option 1: Fix in place with indexes and keyset paging.

Keep the existing notes and folders tables, add focused indexes and a normalized note title key, and page both note surfaces with a stable cursor. Reading only a short content prefix keeps data transferred to PHP small without a new preview table.

**Implementation skills**: `laravel-best-practices` (`pedrojrsolatorio/notes-app`, `.agents/skills/laravel-best-practices/`) · `livewire-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/livewire-development/`) · `testing-best-practices` (`pedrojrsolatorio/notes-app`, `.agents/skills/testing-best-practices/`)

## Feature design

**Data model sketch**:

| Entity | Fields | Rules |
|---|---|---|
| `users` | Existing fields | One user owns many notes and many folders. No change. |
| `folders` | Existing `id`, `user_id`, `portable_id`, `name`, `name_key`, `version`, timestamps | No change. The 1,000 folder target is covered by the user plus title key and user plus folder reads. |
| `notes` | Existing `id`, `portable_id`, `user_id`, nullable `folder_id`, `title`, `content`, `version`, reminder fields, timestamps, `deleted_at`. Add nullable `title_key` string of 510 characters. | `title_key` is server derived from `title` by the same trim and UTF-8 lowercase algorithm as `Folder::nameKeyFor`. It stays unchanged on trash and restore. No new uniqueness rule. |
| Sync note record | Existing note payload plus `title_key` | The server sends `title_key` with `title` and ignores any client supplied value. Dexie stores it for local title pages. Reminder fields remain server only. |

Server indexes on `notes`:

1. `(user_id, updated_at, id)` for newest and oldest pages.
2. `(user_id, folder_id, updated_at, id)` for folder pages.
3. `(user_id, deleted_at, updated_at, id)` for Trash pages.
4. `(user_id, title_key, id)` for title pages.

Local Dexie indexes on the `notes` table:

1. `[user_id+updated_at+portable_id]` for newest and oldest pages.
2. `[user_id+folder_portable_id+updated_at+portable_id]` for folder pages.
3. `[user_id+deleted_at+updated_at+portable_id]` for Trash pages.
4. `[user_id+title_key+portable_id]` for title pages.

Cursor contract:

1. The wire value is versioned base64url JSON with `v`, `sort`, `filter`, `key`, and `anchor`.
2. `sort` is `newest`, `oldest`, `title_asc`, or `title_desc`, matching the existing `sortBy` values. `filter` names search text, folder or All Notes, and Trash state. `key` is the sort value at the last row, with `updated_at` represented as UTC ISO 8601 with microseconds and `title_key` represented as stored. `anchor` is the note public id.
3. Each next page compares the pair made from `key` and the anchor row's stable id in the active ordering direction. The server uses the mapped `notes.id`; Dexie uses `portable_id`. Newest and title Z to A use descending comparisons; oldest and title A to Z use ascending comparisons. This keeps title direction in the cursor and avoids offsets.
4. On the server, the anchor is mapped once through the owner scoped filtered query to the note id used by the database indexes. In Dexie, the anchor is the portable id used directly by the local indexes.
5. A cursor is accepted only when its version, sort, and filter match current state and the anchor exists in the owner scoped filtered query. Otherwise the read restarts at first page with a status message.

**State transitions**:

1. List state starts at first page with no cursor. Load more moves to more loaded with the returned opaque cursor. The first load with no next cursor moves to exhausted.
2. An explicit sort change, folder change, Trash view change, search commit, or offline sync revision advance moves any list state back to first page. Live debounced search keystrokes do not move list state or focus.
3. A failed load does not change list state. Rows, cursor, and selected note stay as they were.
4. When the selected note no longer exists in the full current filter, selection moves to none and the editor clears.
5. End of list is detected by asking for 51 rows and displaying at most 50. The total matched count is read only on the exhausted load.

**API surface**:

| Surface | Method or action | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `notes.index` | `loadMoreNotes()` | Current `search`, `sortBy`, folder or trash state, and plain validated cursor state | At most 50 appended note rows, next cursor, end of list flag, status message id, count when exhausted | Session and verified email through the existing route | Invalid cursor restarts first page with status; 419 follows the current Livewire session handling |
| `/notes` | Existing Livewire render | Existing search, sort, folder, and trash inputs | First 50 rows and first cursor | Session and verified email | Reuse current empty and error messages |
| `/sync` GET | Existing endpoint | Existing `since` and `limit` inputs | Existing note records plus server derived `title_key` | Session and verified email with CSRF | Existing 401, 302, 419, 429, and 410 behavior |
| `offline-app.js` | Local list renderer | Current user id, search, sort, folder or trash state, and local cursor state | At most 50 local rows, next cursor, end of list flag, status message id, count when exhausted | Local store scoped by user id | Reuse current empty, error, needs sign in, and sync messages |

**Value sourcing**:

| Action | Value produced or displayed | Source |
|---|---|---|
| Initial or paged list read | Owner scope | `auth()->user()` on the server, current user id in Dexie |
| Initial or paged list read | Search text | Existing committed search input state on each surface |
| Initial or paged list read | Sort | Existing `sortBy` state on each surface, limited to newest, oldest, title A to Z, and title Z to A |
| Initial or paged list read | Folder or trash filter | Existing selected folder and trash view state on each surface |
| Initial or paged list read | Page cursor | Versioned base64url value derived from active sort, filter, sort key, and public note anchor |
| Initial or paged list read | Sort key and anchor | `updated_at` plus public id for newest and oldest, `title_key` plus public id for either title direction |
| Initial or paged list read | Server keyset id | One owner scoped lookup from the public anchor to `notes.id` |
| Initial or paged list read | Preview source | SQL `substr(content, 1, 2000)` on the server, first 2,000 local characters in Dexie |
| Initial or paged list read | Preview text | Existing decode, tag strip, and 120 character display rule applied to the preview source |
| Initial or paged list read | Newly loaded count | Number of displayed rows in the successful load |
| Initial or paged list read | Total matched count when exhausted | Count from the same owner scoped and filtered query on each surface |
| Load more | Status text | Fixed app strings that contain counts only |
| Load more | Status message id | Monotonic message id set by the load result, exposed through a stable status hook for tests |
| Load more | Focus after success | The Load more control reference, then the list heading when the control unmounts |
| Load failure | Status text | Fixed failure string from the load result, with no payload or cursor value |
| Sort or filter commit | Focus target | The list heading reference |
| Search keystroke | Focus target | The search input, unchanged |
| Selection drops out | Editor state | Owner scoped existence check for the selected note against the full current filter |
| Sync pull | Note `title_key` | Server `notes.title_key` derived from server `title` |
| Local title page | Note `title_key` | Stored sync payload field in Dexie, or local derivation when an older server omits it |
| Sync refresh | Reset dedupe key | Local `last_revision` after the sync run applies |

**Key invariants**:

1. Every server read starts from `auth()->user()`, and every local read filters by the current user id.
2. Page size is 50 on both surfaces. End of list uses a 51 row read and never displays the extra row.
3. A cursor is versioned, base64url encoded, plain validated state. It is never an offset and never trusted from the client.
4. A cursor is accepted only when its sort and filter match current state and its anchor exists in the owner scoped filtered query.
5. Ordering is explicit. Newest uses `updated_at desc, id desc`. Oldest uses `updated_at asc, id asc`. Title A to Z uses `title_key asc, id asc`. Title Z to A uses `title_key desc, id desc`.
6. `title_key` follows `title` on every server write path, including sync apply, backup restore, quick capture, and direct note edits. Client supplied `title_key` is ignored.
7. During backfill, rows with null `title_key` fall back to `LOWER(title)` for title sort. After backfill, every valid note has a title key.
8. Edits to a mutable sort key can reposition a row. A client resets paging when a sync revision advances or a local edit changes a sort key. The stable paging guarantee covers inserts and deletes between loads.
9. A list query never loads full `content` into PHP for the page.
10. No status message, live region, toast, or log line contains note title, note content, title key, or cursor value. Each announcement carries a message id so once only behavior can be tested.
11. Reminder fields remain outside the sync payload and outside local note records.
12. The service worker still never caches `/sync` or attachment routes.

**Security model**:

1. Authorization is unchanged. The existing session, verified email middleware, owner scoped relations, and CSRF protection remain the boundary.
2. A forged or stale cursor cannot widen a query. The test injects a cursor from another user directly into the validated public state and confirms first page behavior with that user's own data only.
3. `title_key` has the same sensitivity as `title`. It is stored and sent only with the note it belongs to.
4. No new endpoint is added, so no new rate limit is needed. Livewire and the existing sync route keep their current protection.

**Configuration required**: No new environment variable or credential. The benchmark uses a PHPUnit group that CI excludes from timing, while CI runs the query count guard in the default suite.

**Critical test scenarios** (each maps to an acceptance criterion in Requirements):

1. Happy path: a user with 10,000 notes loads `/notes`, waits for the measured read, clicks Load more, and receives 50 new rows with focus still on the button, verifies **AC-1**, **AC-4**, **AC-10**.
2. Stable paging case: a note is inserted and a note is deleted while a page boundary is held, then the next page loads without a duplicate or missing eligible row, verifies **AC-2**.
3. Sort case: newest, oldest, title A to Z, and title Z to A each page through a mixed set with shared timestamps and duplicated titles, verifies **AC-2**, **AC-3**.
4. Filter case: search commit, folder, and Trash each use the same cursor contract and reset on explicit filter change, verifies **AC-2**, **AC-5**.
5. Debounce case: typing in search does not move focus or announce a reset until the search is committed, verifies **AC-5**.
6. Selection case: the selected note is removed from the full current filter by search or folder change, and the editor clears with focus on the list heading, verifies **AC-5**.
7. Offline case: `/app` pages through local notes, then a sync revision advances and the list resets to first page with one refresh announcement, verifies **AC-6**, **AC-12**.
8. Data case: notes with unicode titles, trimmed titles, mixed case titles, and trashed state receive correct keys, indexes, and deterministic chunked backfill values, verifies **AC-7**, **AC-3**.
9. Preview case: a very long note body returns only the bounded preview source into PHP and the rendered preview stays within 120 display characters, verifies **AC-9**.
10. Auth case: a second user injects a cursor from the first user into plain validated state and receives only their own first page with a status message, verifies **AC-8**.
11. Cursor case: a cursor with the wrong version, wrong sort, wrong filter, or missing anchor restarts at first page with a status message, verifies **AC-8**.
12. Benchmark case: the seeded benchmark misses 500 ms and verification fails, verifies **AC-1**, **AC-10**.
13. Offline benchmark case: local measured paths miss 500 ms in the browser timing check and verification fails, verifies **AC-12**.
14. Skew case: a client that expects `title_key` receives an older sync payload and still renders title pages, verifies **AC-6**, **AC-11**.
15. Regression case: note, folder, search, sort, trash, quick capture, reminder, attachment, backup, and sync suites pass, verifies **AC-11**.

## Build plan

1. Add the target migration and write paths: nullable `notes.title_key`, the four read indexes, title normalization with the folder name key algorithm, deterministic 500 row chunk backfill, and server derived `title_key` in the sync note payload; ignore client supplied values and fall back to local derivation when an older server omits the field; cover server writes and old row backfill, satisfies **AC-3**, **AC-7**, **AC-11**.
2. Add the shared server paged read for `/notes` with owner scoping, 50 displayed rows from a 51 row read, bounded preview source, versioned validated cursor, public id to internal id mapping, explicit ordering, and newest sort as the first thin end to end thread; wire the Load more action, status message ids, and focus behavior, satisfies **AC-1**, **AC-4**, **AC-8**, **AC-9**, **AC-10**.
3. Extend the server paged read to search commits, folder, Trash, oldest, and both title sort directions with stable tie breaks, existence checked selection clearing, end of list count, explicit focus behavior, and empty and error reuse, satisfies **AC-2**, **AC-3**, **AC-4**, **AC-5**, **AC-8**.
4. Add matching Dexie indexes and local paged reads in `offline-app.js` with the same cursor format, 50 row page, retained rows, status message ids, sync revision reset, and old server fallback, satisfies **AC-2**, **AC-6**, **AC-8**, **AC-12**.
5. Add the seeded benchmark group with 10,000 notes, 100 timed reads after a warm up, nearest rank 95th percentile gates for `/notes` and `/app`, end of list count included, query count guards in the default CI suite, and the timing group excluded from CI, satisfies **AC-1**, **AC-10**, **AC-12**.
6. Run focused notes, sync, backup, reminder, quick capture, and attachment tests, then the full suite and Pint, satisfies **AC-11**.

## Consequences

**Positive**:

1. The existing design and routes stay in place.
2. Both note surfaces gain the same predictable paging contract.
3. Search and folder work remain inside the owner scoped query.
4. The benchmark proves the target instead of relying on a feeling that the app is slow.
5. A list read transfers only a short preview source into PHP instead of full note bodies.

**Negative / tradeoffs**:

1. Search with a leading wildcard still scans note bodies inside SQLite and that storage read is not solved by the preview prefix. The benchmark decides whether full text search is needed later.
2. A normalized title key adds migration work and one more value to keep current on every server write path.
3. Keyset paging needs more careful tests than page numbers. Its stable guarantee covers inserts and deletes, not edits that change the active sort key.
4. Offline paging adds local index and cursor state to a client that already owns sync and editor state.
5. The end of list count is a second scoped read and is included in the benchmark because it can dominate the last page.
6. The first sort or filter load discards earlier pages instead of merging them, which is correct but may feel like a reset.

**Neutral**:

1. No new dependency, endpoint, or table is introduced.
2. The sync note payload gains one string field that older clients can ignore.
3. The feature uses the existing status region and empty and error messages.

## Follow-up

1. [ ] Reconsider SQLite full text search if the benchmark shows search is the only miss or if the target grows past 10,000 notes.
2. [ ] Add snapshot tokens if concurrent edits to active sort keys produce visible reordering that feels unstable.
3. [ ] Add a repeatable browser timing check in CI if approved browser tooling becomes available.

## Migration plan

**Strategy**: strangler

**Phases**:

1. Add the nullable `title_key` column and the four read indexes. Leave existing rows with a null key until the backfill runs.
2. Deploy code that writes `title_key` on every server note write path, derives it with the folder name key algorithm, and includes it in sync note records. Older clients ignore the added field. Client supplied values are ignored. A newer client derives locally when an older server omits the field.
3. Backfill existing notes in a chunked migration using 500 rows per chunk and deterministic `Folder::nameKeyFor` equivalent values. The migration completes before the release that makes title sort rely only on `title_key`.
4. Add paged reads and verify both surfaces against the benchmark and focused tests.

**Rollback**: Revert the code first and leave the added column and indexes in place. They are unused by the old code and do not change existing note behavior. The sync note payload returns to its previous shape. Older clients are unaffected. Newer clients fall back to local derivation when the field is absent. The backfill values stay as harmless extra data until a later cleanup.

**Risks**: A write path could miss the title key and leave a stale value. A migration using 500 row chunks could still hold locks longer than expected on a large table. A client that requires `title_key` could break if it receives an older sync payload. Focused tests cover every server write path, deterministic values make the migration safe to stop and restart before success, and the local fallback keeps title pages working across server rollback.

## Rationale

Reasoning and options: see [rationale.md](rationale.md).
