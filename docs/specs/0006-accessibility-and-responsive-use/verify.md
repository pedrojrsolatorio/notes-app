# Verify: Accessibility and responsive use

Run this on a machine with PHP 8.3, Node 22, and the browsers and assistive technology listed below. The installed app checks need `npm run build` and either `https` or `localhost`.

## Setup

1. Run `composer install` and `npm install`.
2. Run `php artisan migrate` if the database is new.
3. Run `npm run build`.
4. Start the app with `composer run dev` or with `php artisan serve` and `npm run dev`.
5. Run `php artisan test --compact` and confirm the suite is green before the manual pass.

## Checks

1. **Automated DOM checks (AC-16)**
   - Run `php artisan test --compact tests/Feature/AccessibilityTest.php`.
   - Confirm the checks cover the Livewire page field labels, the skip link, one main landmark, toolbar names, pressed state bindings, shortcut hints, and folder action names.
   - Confirm the checks do not try to test the offline DOM, which is built in the browser.
   - Confirm the test run adds no new package to `composer.json` or `package.json`.

2. **Keyboard and focus (AC-1, AC-8, AC-13)**
   - Load `/notes` and press Tab from the top of the page.
   - Confirm the skip link appears first, Enter moves focus to the notes main content, and the focus ring is visible.
   - Tab to a note, press Enter, and confirm the note opens. Press Space on another note and confirm it opens.
   - Tab into the editor toolbar and operate every control with Enter or Space. Open the More menu below 768 pixels and operate the secondary controls.
   - Save a note and confirm focus stays in the editor. Save with an invalid title and confirm focus moves to the title field instead.
   - Delete a middle note and confirm focus moves to the next note. Delete the last note and confirm focus moves to the previous note, or to the list heading when none remain.
   - Restore a note in Trash and confirm focus moves to the restored note control, or to the Trash heading when it leaves the view.
   - Change the note folder select and confirm focus stays on that select. Delete the selected folder and confirm focus returns to the select.
   - Open the editor below 1024 pixels, press the back control, and confirm focus returns to the selected note row.
   - Open quick capture with `Alt+Shift+N`, confirm focus is in the text area, close it, and confirm focus returns to the opener. Hide the opener and confirm focus falls back to the header button or the list heading.
   - Press `N` alone and `Ctrl/Cmd+Shift+N` and confirm quick capture does not open.
   - Confirm the quick capture tooltip, the modal hint, and `aria-keyshortcuts` show `Alt+Shift+N`.

3. **Labels, landmarks, and toolbar (AC-2, AC-3, AC-4)**
   - Inspect the search, sort, title, folder, and quick capture fields. Confirm each has a visible label and a programmatic name.
   - Inspect the folder action control. Confirm its name includes the folder name.
   - Inspect the editor toolbar. Confirm it has a group name, each control has a name, and each toggle exposes pressed state.
   - Confirm the toolbar wraps into rows at 320 and 375 pixels, and the secondary controls move into the More menu below 768 pixels.
   - Confirm the editor has a short visible hint and a static screen reader description that names the toolbar.
   - Confirm the page has one main landmark and a logical heading order.

4. **Validation and status (AC-9, AC-10, AC-19)**
   - Save a note with an empty title and confirm the error appears inline, uses an alert, is linked to the field, and focus moves to the field.
   - Try an invalid folder, a bad attachment upload, an empty quick capture, and an invalid reminder. Confirm each error follows the same pattern.
   - Confirm the attachment error stays visible until corrected or dismissed and is linked to the file input.
   - Confirm the empty quick capture hint is not announced as an alert before submit.
   - Save a valid note and confirm `/notes` announces success once, through the toast, with no second live region message.
   - Go offline and online on `/notes` and confirm the status text changes and is announced.
   - Go offline and online on `/app` and confirm the full sync status changes and is announced once.
   - Confirm `/notes` status covers online, offline, save, upload, and validation. Confirm `/app` status covers online, offline, syncing, needs sign in, error, last sync, and pending count.
   - Confirm no status, live region, toast, or log line contains a note title or note content.
   - Cause a server error and confirm the log line shows the exception class and a correlation id, with no payload or bindings.
   - Confirm offline `last_error` holds a safe message only.

5. **Responsive and touch (AC-5, AC-6, AC-7)**
   - Check 320, 375, 768, 1024, and 1440 pixel widths.
   - Below 1024 pixels, confirm the list and editor are separate views, the back control returns to the list, and folders appear above the list.
   - At 1024 pixels and up, confirm folders, list, and editor appear in three columns.
   - Resize across 1024 pixels and confirm the selected note and focused control are preserved.
   - Confirm the page does not scroll horizontally at 320 pixels. Confirm tables, code blocks, and long strings wrap or scroll inside the editor.
   - Confirm primary buttons, note actions, folder controls, status controls, and modal controls are at least 44 by 44 pixels with spacing.
   - Confirm toolbar controls are at least 24 by 24 pixels with spacing and stay reachable through the toolbar or the More menu.

6. **Color, motion, and forced colors (AC-11, AC-12)**
   - Check the available dark theme with a contrast checker on body text, secondary text, buttons, borders, focus rings, errors, and status text.
   - If a light theme exists, check it too.
   - Turn on OS forced colors and confirm every control and state remains visible.
   - Confirm no state relies on color alone.
   - Turn on `prefers-reduced-motion` and confirm no essential action depends on animation.

7. **Table width (AC-14)**
   - Open a note and insert a table.
   - Use the width menu to apply narrow, medium, wide, and reset with the keyboard.
   - Repeat with touch on a phone or tablet.
   - Confirm narrow, medium, and wide set the table to about 50, 75, and 100 percent of the 720 pixel design width.
   - Confirm the menu shows the matching preset within 40 pixels, and shows none after a manual drag that is far from a preset.
   - Save, reload, and confirm the chosen width persists.
   - Confirm reset clears every cell `colwidth`.
   - Open the same note in `/app` and confirm there are no resize handles, existing widths are preserved, and the table auto sizes.

8. **Screen reader matrix (AC-17)**
   - VoiceOver on Safari for macOS: navigate the notes list, toolbar, More menu, status, validation, and quick capture.
   - VoiceOver on Safari for iOS: check the note list, editor, folders, and quick capture.
   - NVDA on Chrome or Firefox for Windows: check names, roles, pressed state, live status, and errors.
   - Android Chrome: run a smoke test on the list, editor, folders, and quick capture.
   - Confirm each control is announced with a useful name and role, and each status change is announced once.

9. **Offline parity (AC-15, AC-17)**
   - Install the app from `/app`.
   - Run `npm run build`, load the app once online, then set the browser to offline and reload cold.
   - Confirm the list, folders, editor, labels, skip link, focus moves, status text, and touch targets match the online rules.
   - Confirm the sign in state also has one main landmark and a skip link.
   - Edit a note offline, confirm the pending state, then reconnect and confirm sync status.
   - Expire the session, reload `/app`, and confirm local notes stay readable, the app asks for sign in, and sync resumes after sign in returns to `/app`.
   - Confirm native `prompt` and `confirm` dialogs are still used.
   - Confirm the offline app has no formatting toolbar.
   - Confirm the offline editor stays mounted after a save and keeps focus, cursor, and undo history.

10. **Regression (AC-18)**
    - Create, edit, save, search, sort, trash, and restore a note on `/notes`.
    - Create, rename, and delete a folder.
    - Use quick capture and confirm the first line becomes the title.
    - Set, change, retry, and clear a reminder.
    - Upload, download, and delete an attachment.
    - Run a sync from `/app` and confirm the note appears on `/notes`.
    - Run the existing notes, quick capture, reminder, attachment, and sync tests.

11. **Final suite and style**
    - Run `php artisan test --compact` and confirm the full suite passes.
    - Run `vendor/bin/pint --dirty --format agent` on any touched PHP files.

## Results (2026-11-17)

- Implemented across `/notes`, `/app`, the shared layout, Tiptap, the service worker, and the Fortify login return path.
- Static checks passed: Blade compilation for the touched views, `node --check` for the touched JavaScript, and `php -l` for `FortifyServiceProvider.php`.
- Added `tests/Feature/AccessibilityTest.php` for the Livewire DOM contract and updated the shortcut and attachment-error assertions in the existing tests.
- Automated checks from a container copy of the app (outside the slow WSL mount): focused `AccessibilityTest`, `QuickCaptureTest`, and `NotesTest` pass 71 tests / 298 assertions after fixing the shared `noteListQuery()` return type.
- Full suite: 177 passed / 735 assertions. The only 13 failures are pre-existing backup tests that need `ext-zip`, which is absent from the bare PHP container; the zip extension could not be installed because the sandbox has no apt network access.
- `vendor/bin/pint --format agent` passes on the touched PHP files. `phpstan analyse` reports 56 errors, all in pre-existing offline sync/backup files, not in the touched accessibility files.
- Static checks passed: Blade compilation for the touched views, `node --check` for the touched JavaScript, and `php -l` for `FortifyServiceProvider.php`.
- Not run: `npm run build` and the manual browser and assistive technology matrix. The local npm `vp` symlink is broken. These checks remain required on the dev machine before the feature can move to done.
