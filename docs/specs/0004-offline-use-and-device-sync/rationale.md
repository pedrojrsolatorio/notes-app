# Rationale: Offline use and device sync

## Context

The notes app is a server rendered Laravel application. The notes page is one Livewire component, the editor is Tiptap, notes and folders live in the database, and attachments live on the local disk. There is no API, no local data store, and no sync engine. The app is private and single user, so sharing, roles, and tenant isolation are not part of this decision.

The scope asks for offline edits to be kept and for two devices not to lose note content silently. That makes this more than a read only cache. A phone user on a weak connection needs to open the app, read, edit, create, and file notes, then trust that the work reaches the server later. The current Livewire page cannot do that because every action is a server request.

The forces are the existing Laravel and Livewire stack, a small personal data set, no operations team, and no wish to add a hosted service. The app already has stable `portable_id` values on notes and folders, an unused `version` column on notes, soft deletes on notes, and hard deletes on folders. Those facts shape the sync design.

> ⚠️ Premise note: This feature adds a second notes interface and a sync engine, which is the largest slice in the scope. A read only offline cache would be much smaller, but it would not meet the scope's requirement that offline edits are kept. The dedicated installed app avoids rewriting the Livewire page, but it means two interfaces share the same rules. That duplication is a conscious tradeoff, not an accident.

## Options considered

### Option 1: Read only offline cache

Cache the last rendered notes list and note bodies in the browser, and require a connection for any edit.

**Pros**:
- Small change to the existing page.
- No sync engine, no outbox, and no conflict rules.
- Works for reading on a plane.

**Cons**:
- Offline edits are impossible, which fails the scope's done condition.
- The cache goes stale with no clear recovery path.
- It still needs a service worker and a local store, so part of the cost remains.

### Option 2: Installable PWA with Dexie and a Laravel change feed

Build a dedicated client view for the installed app. It stores notes and folders in IndexedDB through Dexie, queues local changes in an outbox, and syncs through a small Laravel API backed by a `sync_changes` table.

**Pros**:
- Reuses the existing Laravel session, database, and Tiptap editor.
- Keeps the current Livewire page intact.
- A server change log gives a reliable cursor for edits, deletes, restores, and folder tombstones.
- No hosted sync service, no new database, and no new environment variables.
- Conflict copies protect note text when devices disagree.

**Cons**:
- A second notes interface to build and maintain.
- A sync engine, an outbox, and conflict rules are real complexity.
- Local notes are not encrypted beyond the device lock.
- Sync only runs while the app is open.

### Option 3: Hosted sync service

Use a service such as PowerSync or ElectricSQL to replicate the database to the client.

**Pros**:
- Mature replication and conflict tooling, if the service fits the database.
- Less custom sync code.

**Cons**:
- These services are built around Postgres or a specific database and do not fit the current Laravel and SQLite setup cleanly.
- Adds a hosted dependency, a second place where private notes live, and an operating cost.
- The data set is small enough that the service is more machinery than the problem needs.

### Option 4: Native mobile app

Build a separate iOS and Android client.

**Pros**:
- Best control over offline storage and device integration.
- Native background sync is more reliable than browser background sync.

**Cons**:
- A second codebase and a second release process.
- Duplicates the notes rules and the editor.
- Far more work than the current web app and personal use justify.

## Rationale

Option 2 fits the actual stack and the size of the problem. The app is already a Laravel application with a session, a database, and a Tiptap editor. Adding a small sync API and a browser store keeps the server as the source of truth and avoids a hosted service that would not match the database. The existing `portable_id` values mean a note or folder can cross between devices without exposing server integer ids. The existing note `version` column becomes the base for conflict detection, which is a small change to a column that was already there.

A change log is chosen over a timestamp cursor because the app has hard folder deletes and soft note deletes. A timestamp scan can miss a hard folder delete, and equal timestamps make the cursor unreliable. One change log row per mutation gives the client a single integer cursor and a reliable tombstone for folder deletes. The cost is one table that grows with edits; that cost is acceptable for a personal notes app and can be pruned later.

Conflict copies are chosen over last write wins because the scope says note content must not be lost silently. A last write wins rule is easier, but it can erase a thought the user typed offline. A conflict copy is a normal note, so it is searchable, editable, and easy to delete. A field level merge or a CRDT is more complex than a private notes app needs.

The installed app gets its own client view instead of replacing the Livewire page. Replacing the page would give one interface, but it would rewrite a working feature and put every online interaction through the new client. The dedicated view keeps the current page and its tests, and it lets the installed app use local data directly. The tradeoff is two interfaces with the same rules, which the build plan keeps small by reusing Tiptap and the note validation rules.

Session authentication is chosen over a stored API token because the installed app calls the same origin and the app already has Fortify, CSRF protection, and a verified email requirement. The cost is that an expired session blocks sync until sign in; local notes stay readable, which is the right balance for a private device. Local encryption with a passphrase would be stronger, but it adds a second password, a key recovery problem, and a daily unlock before offline use. Device storage protection is the trust model the app already uses.

Background sync is left out because service worker background sync is unreliable on mobile browsers and would need its own authentication and CSRF story. Syncing while the app is open is predictable and covers the real use: open the app, see the pending count, and let it finish.

Attachments stay online only because caching files adds storage limits, eviction, and a second conflict model. The note text is the part a user needs on a plane; the attachment can wait for a connection.
