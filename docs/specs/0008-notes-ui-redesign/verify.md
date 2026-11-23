# 0008. Notes UI redesign — verify

Steps for `/check verify` after the build. Run the browser checks with a real browser on the dev machine; run the test commands from the project root. Tick an acceptance criterion only when its check passes on both surfaces where it applies. On FAIL or BLOCKED, tick nothing and leave the spec status unchanged.

## Automated

1. `php artisan test --compact` — the full suite passes; only intentional assertion updates are allowed, no weakened assertions (**AC-7**).
2. `vendor/bin/pint --test` on touched PHP files — no formatting regressions (**AC-7**).
3. `node --check` on touched JS files and `node --test tests/js/*.test.js` — pass (**AC-7**).
4. `npm run build` completes and `public/build` manifest updates (**AC-5**, **AC-9**).

## Browser: `/notes` (light and dark, both checked)

5. Sidebar shows smart lists "All Notes" and "Trash" above the flat folder list; switching folders and Trash updates the list; the active item has a visible pill and `aria-current` (**AC-1**).
6. Collapse control turns the sidebar into an icon rail with accessible names, the state survives a reload under the shared `notes-sidebar-collapsed` key, and the control is hidden below 1024 pixels (**AC-1**).
7. Note list rows are borderless with hairline separators showing title, one line preview, and a date in `M j, Y` local format (check a note updated yesterday and one updated last year, on `/notes` and `/app`); Load more still pages 50 rows (**AC-2**).
8. Search shows the "N notes" count only while search is non-empty, the count is not announced by a screen reader (it is not a live region), the clear button has `aria-label="Clear search"`, and all four sort orders still work (**AC-2**, **AC-6**).
9. Open a note: title renders as the large first line of the document with the visible "Title" label still present, saving an empty title still shows the inline error, save and folder assignment work (**AC-3**, **AC-7**).
10. Toolbar is a slim unboxed row that wraps when needed, with visible pressed states; below 768 pixels only Bold, Italic, Underline, Strike, and More show, every control stays keyboard reachable, and reminder actions live in a row level dropdown (**AC-4**, **AC-8**).
11. Empty states match the pinned copy ("No notes yet" with New note, "No matching notes" with Clear search, Trash with headline and line only); skeleton rows appear only on `/app` during its first load while `/notes` keeps its rows; saves show the existing Saving text (**AC-6**).
12. Warm neutrals, perceptible hairline borders in both themes, soft shadows, 12 pixel surface radius, black primary buttons, blue accent only on links and focus with focus rings at 3:1 or better; both themes stay legible with AA contrast (**AC-5**, **AC-8**).
13. Below 1024 pixels the list and editor swap as master and detail; folders show from medium widths; the FAB shows on small screens (**AC-10**, **AC-8**).

## Browser: `/app` (dark only)

14. Same sidebar, list, editor, toolbar, and states as `/notes`, using the same token classes; folder rename and delete still use the native `prompt` and `confirm` dialogs (**AC-9**).
15. Offline check (DevTools offline): shell loads, list opens, a note edits and saves locally, sync status and the sign in path still behave (**AC-9**).
16. No Dexie, sync, or service worker logic changed: `git diff` on `resources/js/offline-app.js` touches presentation only, and the test suite passes, confirms it (**AC-9**).

## Accessibility regression (spec 0006)

17. Re-run the spec 0006 verify steps: visible labels, toolbar names and pressed states, focus indicators, 44 pixel targets, live status regions, skip link (**AC-8**).

## Outcome

- All pass: the redesign is verified; set this spec and its scope row (once enrolled) as done through the normal flow.
- Any fail: report the failing criterion, do not tick it, do not change status.
