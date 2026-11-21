# 0007. Performance as notes grow: rationale

## Context

The notes app currently loads the full note list on both surfaces. On `/notes`, the Livewire component builds one query for All Notes, a folder, or Trash, applies search with a leading wildcard, sorts in the database, and then calls `get()` with no limit. The installed app keeps a full local copy through Dexie and filters that copy in the renderer. This is comfortable for a small personal collection but becomes slower and less predictable as notes accumulate.

The agreed target is 10,000 notes, with a 95th percentile read under 500 ms on both `/notes` and `/app`. Sync and backup throughput are explicitly outside this slice. The work must fit the existing Laravel 13, Livewire 4, Flux 2, SQLite, Tiptap, and Dexie stack, preserve owner scoped reads, and add no new dependency. It must also preserve the current search, sort, folder, Trash, empty, error, and status behavior from the earlier features.

The current list offers both title A to Z and title Z to A. The cursor must carry the selected direction so later pages keep the same ordering.

The main risk is not raw query time alone. Offset paging can duplicate or skip rows when notes change between loads. Mutable sort keys can reposition rows even with keyset paging. Loading full note bodies into PHP for a page can waste memory on long notes. A new search engine or preview table would add state to keep consistent with sync and backup, both of which are already sensitive areas. The chosen shape must therefore be small enough to verify and honest about the failure modes it does not solve.

## Options considered

### Option 1: Fix in place with indexes and keyset paging

Keep the existing tables, add a normalized note title key and four read indexes, page 50 rows at a time with a versioned cursor, and read only a short content prefix into PHP for previews.

**Pros**:

1. It uses the current stack and owner scoped relations.
2. It avoids new infrastructure, new dependencies, and new tables.
3. It gives stable page boundaries for inserts and deletes.
4. It fits a Tracer Bullet build, because the first page, cursor, and UI can be proven end to end before search, sorts, and offline are thickened.
5. The benchmark can prove or reject the target without broad changes.
6. It preserves both current title sort directions and carries the direction in each page cursor.

**Cons**:

1. Search with a leading wildcard remains a scan inside the user's notes.
2. Title sort needs a derived column that every server write path must maintain.
3. Keyset paging needs more careful tests than page numbers.
4. Edits that change an active sort key can still reorder rows.

### Option 2: Add SQLite full text search

Create a full text index for note title and content, then use it for search pages.

**Pros**:

1. Search can become substantially faster at larger sizes.
2. It keeps the search inside SQLite rather than adding a hosted service.

**Cons**:

1. It adds a virtual table or generated search structure to maintain.
2. It changes search behavior and ranking rules.
3. It overlaps backup and sync work that is explicitly outside this slice.
4. It is premature before the benchmark shows that search is the actual bottleneck.

### Option 3: Use offset paging only

Replace the full list with page numbers and skip rows with a database offset.

**Pros**:

1. It is simple to build and easy to explain.
2. It keeps a visible page number that users understand.
3. It needs no new indexes for page stability.

**Cons**:

1. A note change between pages can duplicate or omit rows.
2. It does not solve the long body transfer unless the query is narrowed anyway.
3. It fails the stable paging rule chosen for this feature.

### Option 4: Use a lower title expression index with bounded previews

Create an expression index on `LOWER(title)` for title sort and keep the bounded content prefix for previews. Do not add `title_key` or change the sync payload.

**Pros**:

1. It avoids a derived column, backfill, sync field, and write path hooks.
2. It matches the current title sort expression exactly for ASCII text.
3. It is the smallest schema change that still improves title paging.

**Cons**:

1. SQLite `lower()` is ASCII based, so it does not match the existing folder `name_key` rule for Unicode case and whitespace trimming.
2. It does not help search at all, which is the largest acknowledged performance risk.
3. Expression index support depends on SQLite behavior that this app has not relied on before.

## Rationale

The 10,000 note target and 500 ms read target are specific enough to measure, and the existing code gives a clear place to start. Option 1 is the smallest change that touches every layer without adding new infrastructure. It keeps the owner boundary intact, keeps backup and sync behavior stable apart from one additive field, and lets the benchmark decide whether search needs more work later.

Both title directions remain available to avoid removing an existing sort choice. The cursor uses `title_asc` or `title_desc`, and continues with the matching direction for `title_key` and its stable ID tie breaker.

Option 2 is the strongest future path if search misses the target, but it would add a new search structure and a second set of results to keep in step with note writes. Option 3 is easier to build but fails the chosen invariant around stable pages. Option 4 is materially simpler and was considered seriously, but `title_key` follows the existing folder `name_key` pattern with Unicode trim and UTF-8 lowercase rules, and it gives one explicit value that both surfaces can page by. The runner up is Option 4 for the title sort part, with Option 2 kept as the search follow up.

The stable paging claim is deliberately narrow. Keyset paging protects inserts and deletes between loads. It does not make mutable sort keys immutable. An edit to `updated_at` or `title` can move a row, so the client resets paging when the sync revision advances or a local edit changes a sort value. Snapshot tokens would make this stronger, but they would add server state outside this slice.

The preview prefix bounds what reaches PHP, not what SQLite may read from disk. A leading wildcard search still scans note bodies. The end of list count can also scan the full filtered set, so it is included in the benchmark. The timing pass runs locally against a seeded SQLite database with no competing load. CI keeps a query count and shape guard, because wall clock timing in CI would be noisy. A later browser timing run is the follow up for real offline latency.

The migration is additive and cautious. `title_key` starts nullable, code writes it on every relevant server path, existing notes are backfilled in deterministic chunks, and title sort relies on it only after backfill. That sequence avoids one huge update and keeps rollback simple. Both skew directions are handled: old clients ignore the added sync field, and newer clients derive locally when an older server omits it.

## Code map evidence

1. `resources/views/components/notes/⚡index.blade.php:708` starts `noteListQuery(User)`, which filters by owner, folder or Trash, and search.
2. `resources/views/components/notes/⚡index.blade.php:726` applies `LIKE %search%` to title and content.
3. `resources/views/components/notes/⚡index.blade.php:735` sorts newest, oldest, or `LOWER(title)`.
4. `resources/views/components/notes/⚡index.blade.php:747` calls `get()` for folders and notes with no limit.
5. `resources/views/components/notes/⚡index.blade.php:977` binds search with a live debounce.
6. `app/Models/Folder.php:21` defines `normalizeName`, and `app/Models/Folder.php:36` defines `nameKeyFor`.
7. `resources/views/components/notes/⚡index.blade.php:417` validates note title with `max:255`.
8. `resources/js/offline-app.js` holds the local Dexie list renderer and current filter state.
9. `app/Http/Controllers/SyncController.php` owns the existing sync note payload.
10. `docs/specs/0004-offline-use-and-device-sync/index.md` defines the local note model and sync rules.
11. `docs/specs/0006-accessibility-and-responsive-use/index.md` defines the status and focus rules reused by Load more.
