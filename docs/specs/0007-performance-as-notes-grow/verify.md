# Verify: Performance as notes grow

Run this on a machine with PHP 8.3, Node 22, SQLite, and a browser for the `/notes` and `/app` checks. The server benchmark runs explicitly on the dev machine with the PHPUnit benchmark group. CI runs query shape checks only. Build the frontend before checking `/app`.

## Setup

1. Run `composer install` and `npm install`.
2. Run `php artisan migrate` if the database is new.
3. Run `npm run build`.
4. Start the app with `composer run dev` or with `php artisan serve`.
5. Sign in with the owner account that will hold the seeded notes.
6. Confirm the benchmark test seeds its own in memory SQLite data and does not touch the development database.
7. Confirm the benchmark group is excluded from the default CI timing run and that the query count guard still runs in the default suite.

## Checks

1. **Benchmark and query shape (AC-1, AC-10, AC-12)**
   1. Run `vendor/bin/pest --group=benchmark tests/Feature/PerformanceAsNotesGrowTest.php`.
   2. Confirm the seed creates 10,000 notes for one user, plus duplicated titles, shared timestamps, long bodies, trashed notes, and notes in folders.
   3. Confirm the benchmark warms up, then takes 100 timed reads per measured path.
   4. Confirm measured server paths include first page, newest Load more, search, folder, Trash, title sort, and the end of list count read.
   5. Confirm the 95th percentile uses nearest rank and is under 500 ms for each measured path.
   6. Confirm a missed target fails the check rather than printing a warning.
   7. Confirm the query count guard rejects an unbounded read, full body transfer into PHP, and an N plus one pattern.
   8. In the browser, time 100 local reads after a warm up for `/app` first page, newest Load more, search, folder, Trash, and title sort. Confirm each 95th percentile is under 500 ms.
   9. Confirm CI only checks local index shape and does not depend on browser wall clock time.

2. **Migration and model rules (AC-3, AC-7, AC-11)**
   1. Inspect `notes` and confirm `title_key` is nullable and is a string of 510 characters.
   2. Confirm the four read indexes exist on `notes`: user plus time plus id, user plus folder plus time plus id, user plus trash plus time plus id, and user plus title key plus id.
   3. Confirm the backfill runs in chunks of 500 with deterministic values.
   4. Confirm normalization trims Unicode whitespace and Unicode line and tab characters at both ends, then lowercases as UTF-8, matching `Folder::nameKeyFor`.
   5. Confirm titles over 255 characters still fail validation, so the 510 character key is storage headroom rather than a longer user input.
   6. Confirm null keys fall back to `LOWER(title)` before backfill and that every valid note has a key after backfill.
   7. Create, edit, quick capture, restore from Trash, sync apply, and import a note. Confirm `title_key` matches `title` after each path.
   8. Submit a sync payload with a forged `title_key`. Confirm the server ignores it and derives the value from `title`.
   9. Simulate an older server response with no `title_key`. Confirm the newer local client still renders title pages.
   10. Trash and restore a note. Confirm `title_key` stays unchanged.

3. **Server list behavior (AC-1, AC-2, AC-3, AC-4, AC-5, AC-8, AC-9, AC-10)**
   1. Load `/notes` with the seeded data. Confirm the query asks for 51 rows and the page shows 50.
   2. Click Load more. Confirm 50 rows append, earlier rows stay, focus stays on the button, and the newly loaded count is announced once.
   3. Keep clicking until the list ends. Confirm the button disappears, focus moves to the list heading, the total matched count comes from a scoped count, and the total is announced once.
   4. Inspect each status message. Confirm it carries a stable message id, contains counts only, and never contains note title, note content, title key, or cursor value.
   5. Force a failed load and confirm the rows, cursor, and selection stay unchanged, the failure is announced once, and retry works.
   6. Decode a returned cursor. Confirm it is versioned base64url JSON with sort, filter, sort key, and public note anchor. Confirm the server maps the anchor once to the internal note id.
   7. Inject cursors with the wrong version, wrong sort, wrong filter, a missing anchor, and an anchor from another user. Confirm each restarts at first page with a status message and returns only the signed in user's data.
   8. Search with several keystrokes. Confirm focus stays in the search input and no reset is announced until the search is committed.
   9. Switch folders, open Trash, and change each sort. Confirm each explicit commit starts at 50 rows and resets to the first page with focus on the list heading.
   10. Sort by title and confirm duplicated titles stay in stable id order.
   11. Select a note, then change the full current filter so that note no longer belongs to it. Confirm the editor clears with an owner scoped existence check and focus moves to the list heading.
   12. Inspect a very long note in the list. Confirm the query transfers only the 2,000 character preview source into PHP and the rendered preview stays within 120 display characters.

4. **Stable paging and mutable sort keys (AC-2, AC-3, AC-6)**
   1. Open two tabs on `/notes` with a long list.
   2. In the first tab, hold a page boundary. In the second tab, insert a note and delete a note near that boundary.
   3. Load the next page in the first tab. Confirm no eligible row repeats or is skipped.
   4. Repeat for newest, oldest, and title sorts.
   5. Edit a note so its `updated_at` or `title` sort key changes. Confirm the client resets to first page rather than pretending the old page boundary is still valid.

5. **Offline list behavior (AC-2, AC-6, AC-8, AC-12)**
   1. Load `/app` online and let the full sync finish.
   2. Confirm the local list shows 50 rows and Load more appends the next 50 with the same cursor format, focus behavior, and status message ids.
   3. Repeat search commit, folder, Trash, and sort paging offline. Confirm the same cursor validation and reset rules.
   4. Reload while offline and confirm the paged list and cursor state still behave correctly from the local store.
   5. Reconnect and advance the sync revision while the list is on a later page. Confirm the list resets to first page once, deduped by revision, and the refresh is announced once.
   6. Confirm the local list reuses the existing empty, error, and needs sign in messages.
   7. Confirm no note title, note content, title key, or cursor value appears in status text or browser logs.

6. **Regression (AC-11)**
   1. Create, edit, save, search, sort, trash, restore, move to folder, and permanently delete a note on `/notes`.
   2. Create, rename, and delete a folder.
   3. Use quick capture and confirm the first line becomes the title.
   4. Set, change, and clear a reminder.
   5. Upload, download, and delete an attachment.
   6. Export and import a backup.
   7. Run a sync from `/app` and confirm note content, folder state, and conflict copies still behave as before.
   8. Confirm reminder fields remain absent from the sync payload and local note records.
   9. Run `php artisan test --compact` and confirm all applicable tests pass.
   10. Run `vendor/bin/pint --dirty --format agent` after any PHP change.

## Results

The two saved regression commands passed on 2026-11-22 (2 tests, 14 assertions). The remaining browser and acceptance checks are still pending.

## Current implementation check

The current benchmark is `php artisan test --compact --group=performance tests/Feature/NotesListPerformanceTest.php`. It supersedes the earlier sample command and path above. The explicit server run and the browser run both used 10,000 notes, 100 samples per path, and the 500 ms p95 target.

Recorded server p95 results were 7.83 ms for first page, 9.92 ms for newest Load more, 12.13 ms for search, 10.43 ms for folder, 10.29 ms for Trash, 9.06 ms for title A to Z, 8.80 ms for title Z to A, and 12.18 ms for the final page count.

Recorded browser p95 results were 28.6 ms for first page, 14.8 ms for Load more, 297.7 ms for search including the 200 ms debounce, 28.4 ms for folder, 28.1 ms for Trash, 11.5 ms for title A to Z, and 29.1 ms for title Z to A.

## Additional value source checks

1. [ ] Switch accounts and confirm the server list uses `auth()->user()` and the local database uses the current user id. Confirm neither list can show another user's notes (AC-8).
2. [ ] Commit different search text on `/notes` and `/app`. Confirm each read uses the current search input and that typing before the debounce commits does not move focus (AC-2, AC-5).
3. [ ] Sort newest, oldest, title A to Z, and title Z to A with tied timestamps and duplicate titles. Confirm the ordering uses the active sort and the specified `updated_at` or `title_key` value with its id tie breaker. Include mixed case, Unicode, and surrounding whitespace (AC-3).
4. [ ] Change folders and switch to Trash. Confirm each read uses the selected folder or current view and resets paging to its first page (AC-2, AC-5).
5. [ ] Insert and delete notes between pages. Confirm the versioned cursor uses the active sort, filter, key, and anchor without skipping or repeating eligible notes (AC-2).
6. [ ] Try cursors with a wrong version, sort, filter, missing anchor, and foreign anchor. Confirm the server maps anchors with an owner-scoped lookup, restarts at the first page, and shows only fixed status text. Confirm titles, content, keys, and cursors do not appear in logs or status text (AC-8).
7. [ ] Load a note with content longer than 2,000 characters. Confirm the server preview source is limited to 2,000 raw characters and both previews apply the existing tag stripping and 120-character display limit (AC-9).
8. [ ] Click Load more. Confirm 50 new rows append, existing rows remain, the count is announced once, and focus stays on the button. Simulate a failed load and confirm rows and cursor remain unchanged, the failure is announced once, and retry works. At the end, confirm the control disappears, the filtered total is counted, and focus moves to the heading (AC-4).
9. [ ] Confirm status text contains only fixed strings with counts and that each load or sync refresh changes the status message id once. Advance the offline sync revision while on a later page and confirm the list resets and announces once (AC-4, AC-6, AC-8).
10. [ ] Apply the migration to representative data. Confirm the nullable 510-character key, four read indexes, deterministic 500-row backfill, and absence of new tables or uniqueness rules (AC-7).
11. [ ] Run focused and full regression suites. Confirm reminder fields remain outside offline sync and local note records (AC-11).

## Saved regression commands

_Added on 2026-11-22 from /develop. Both tests are durable checks in `tests/Feature/NotesTest.php`._

- [x] `php artisan test --compact --filter="guards the notes list query" tests/Feature/NotesTest.php` passes. It proves one list query, `limit 51`, the 2,000 character preview prefix, and no full body selection (AC-10).
- [x] `php artisan test --compact --filter="keeps the note list and cursor unchanged" tests/Feature/NotesTest.php` passes. It proves rows, cursor, and selection stay unchanged after a forced failure, the failure is announced once, and retry succeeds (AC-4).

## Acceptance-criteria coverage

- AC-4 is covered by the forced failure command above.
- AC-10 is covered by the query guard command above.
