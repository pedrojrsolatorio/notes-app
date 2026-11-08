# Verify: Offline use and device sync

Run this on a machine with PHP 8.3, Node 22, and a browser with developer tools. The service worker needs `npm run build` and either `https` or `localhost`.

## Setup

1. Run `composer install` and `npm install`.
2. Run `php artisan migrate` to add `sync_changes` and the folder version.
3. Run `npm run build`.
4. Start the app with `composer run dev` or `php artisan serve` and `npm run dev`.
5. Run `php artisan test --compact` and confirm the server suite is green.

## Checks

1. **First pull and offline shell (AC-1, AC-4, AC-6, AC-6c)**
   - Sign in and open `/notes`.
   - Use the install entry to open `/app` and install the app.
   - Wait for the status bar to show a completed sync.
   - In developer tools, open Application, IndexedDB, and confirm the per user database holds the user's `notes`, `folders`, and `sync_meta` tables with a `last_revision`.
   - Confirm each returned note carries its full content, version, folder portable id, deleted state, and attachment manifest.
   - Set the network to offline, reload `/app`, and confirm the list, folders, and trash still open.
   - Cause many changes and pull them with a small limit; confirm the cursor lands on the last returned change row, and an empty pull leaves the cursor unchanged.
   - Prune or prefill old change rows, then request a `since` older than the retained history and confirm a 410 with the minimum retained revision; confirm the client resyncs from 0.

2. **Offline note edit (AC-2, AC-10b)**
   - While offline, create a note and edit an existing note three times.
   - Confirm one pending row per note, with the latest payload and earliest base version.
   - Confirm the pending count rises and each note shows a pending state.
   - Reload while still offline and confirm the text and pending state remain.
   - Search for a word in the new note and confirm it appears.

3. **Offline folders and trash (AC-3)**
   - While offline, create a folder, rename a folder, move a note into it, and delete another folder.
   - Confirm the notes from the deleted folder appear in All Notes.
   - Trash a note and restore it.
   - Reload while offline and confirm every action remains queued.

4. **Reconnect and retry (AC-5, AC-6, AC-10, AC-10c)**
   - Go back online and confirm sync starts automatically.
   - Confirm the status bar shows the last sync time and a zero pending count.
   - Refresh the server data and confirm each queued change applied once.
   - Press the manual retry control and confirm it does not duplicate notes.
   - Use the network throttle or go offline during a push, then reconnect, and confirm the queue retries without duplicates.
   - Make a change the server finally rejects, such as a duplicate folder rename; confirm the row drops from the outbox, the message shows, and local data stays.

5. **Note conflict (AC-7, AC-7b)**
   - Open the app in two browser profiles or two devices, both signed in as the same user.
   - Go offline in both, edit the same note differently, then bring one online and sync, then the other.
   - Confirm the second sync creates a note titled `Conflict copy of <title>` in All Notes, keeps the first synced version as the live note, and shows a notice.
   - Confirm the conflict copy has the second device's text and that retrying the same change does not create a second copy.
   - Confirm the conflict copy also appears on the first device after its next sync.
   - Permanently delete a note on one device; confirm the other device removes its local copy after sync.
   - Trash a note on one device while editing its title on another; confirm the trash applies without a conflict copy.

6. **Folder conflict (AC-8)**
   - Rename the same folder on two devices while offline.
   - Sync both and confirm the latest server name wins and a notice appears.
   - Delete that folder on one device and rename it on the other, then sync both.
   - Confirm the delete wins, the notes move to All Notes, and the pending rename is rejected without losing note text.
   - Create a folder with an existing name offline and sync; confirm it is rejected and local data is kept.

7. **Session expiry and user scope (AC-9, AC-9b)**
    - With local notes present, delete the session cookie in developer tools.
    - Reload `/app` and confirm the public shell opens the last user's own store, local notes stay readable, and the status shows needs sign in.
    - Make an offline edit and confirm it stays queued.
    - Sign in again and confirm sync resumes and the queued edit applies once.
    - Sign out, sign in as a second user on the same browser, and confirm the second user never sees the first user's local notes or outbox.
    - Verify the browser asked for persistent storage, and that closing the app discards no pending change.

8. **Attachments (AC-12, AC-12b)**
    - Open a note with an attachment while offline.
    - Confirm the note text is readable, the manifest entry renders, and the attachment area says a connection is needed.
    - Go online and confirm upload, download, and delete still work on `/notes`.

9. **Access control (AC-11)**
    - With user A signed in, copy a note portable id.
    - Sign in as user B and call `/sync` with user A's note or folder id through the sync request.
    - Confirm the response returns no user A data and the change is rejected per item.
    - Confirm a request without a session or with a stale CSRF token shows needs sign in, not a raw error.

10. **Update flow (AC-14)**
    - With pending offline changes in the outbox, change the service worker cache version, run `npm run build`, and reload `/app` twice.
    - Confirm the new service worker activates, the local store remains, and the pending changes still sync.

11. **Online regression (AC-13)**
    - Open `/notes` online and confirm quick capture, search, sort, folders, trash, attachments, validation, and toasts still work.
    - Confirm the install entry exists and does not replace the Livewire page.

12. **Final suite**
    - Run `php artisan test --compact` and confirm the full suite passes.
    - Run `vendor/bin/pint --dirty --format agent` on any touched PHP files.
