# Verify: Quick capture · spec 0002 · updated 2026-10-28

_Steps derived from spec 0002 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual

- [ ] Sign in, open `/notes`, and make the window 768px or wider. Click Quick capture. Confirm the modal opens empty with focus in the text box, the header tooltip shows N with a key hint, and the footer shows Ctrl/Cmd+Enter and N. → AC-1 · AC-10
- [ ] Type two lines, "Buy milk" then "2 litres", and press Ctrl/Cmd+Enter. Confirm exactly one note is created, the modal closes, a success toast appears, the view shows All Notes, the search is empty, the sort is Newest, and the new note appears in the list with the expected title and body. → AC-2 · AC-3 · AC-8
- [ ] Before saving, set a non default folder, search text, sort, and selected note, and change the editor content without saving. Capture a note. Confirm the view resets to All Notes with an empty search and Newest sort, while the selected note and the editor content stay as they were. → AC-8
- [ ] Capture one line. Confirm the title is set and the body is empty. → AC-3
- [ ] Capture a first line of 255 code points, then a first line of 256 code points with an emoji at the boundary. Paste a CRLF paragraph and start another capture with a leading blank line. Confirm the title is capped at 255 code points and the overflow, the later lines, and the emoji survive in the body. → AC-3
- [ ] Capture a body containing `<b>bold</b>` and `Tom & Jerry`. Confirm the list preview shows the literal tags and the ampersand, the editor shows the same text, and the stored HTML is one escaped paragraph per line. → AC-11 · AC-12
- [ ] Open capture, type text, and close with Escape. Reopen and confirm the box is empty. Repeat with Cancel, the X, and a click outside the modal. → AC-9
- [ ] Type only spaces, then only tabs, then only a newline, then only an NBSP. Confirm the save control is disabled with the helper message. Submit through a direct client call and confirm an inline message appears and no note is created. → AC-7
- [ ] Double click Save, then open again and press Ctrl/Cmd+Enter twice quickly. Confirm each attempt creates exactly one note. → AC-2
- [ ] Press N with no field focused on the notes page. Confirm the modal opens. Then press N inside the search field, inside the editor, while holding Ctrl/Cmd, Alt, or Shift, with Shift alone, while holding N, during IME composition, and with the folder dialog open. Confirm each does nothing. → AC-4
- [ ] Open Trash. Confirm the header button and the floating button are absent and pressing N does nothing. → AC-6
- [ ] Below 768px, confirm the header trigger is hidden and the floating button is visible at the bottom right and opens the modal. At 768px and wider, confirm the header trigger is visible and the floating button is hidden. → AC-5
- [ ] Sign in as another account and confirm the captured note is absent. Confirm a guest and an unverified account are still redirected from `/notes`. → AC-2 · AC-12
- [ ] Open the captured note, edit and save it, move it to trash, restore it, and upload an attachment. → AC-12
- [ ] In the automated test, force the database create to fail. Confirm the modal stays open, the text remains, and a danger toast replaces the success toast. → AC-9
- [ ] In a Livewire test, call the open and save actions while `isTrashView` is true. Confirm they return silently and create nothing. → AC-6

## Commands

- [ ] `npm run build` or `npm run dev` before the manual checks, so the new Tailwind classes are in the built stylesheet. → AC-1 · AC-5
- [ ] `php artisan test --compact tests/Feature/QuickCaptureTest.php` → the text rule, the title only case, the 255/256 boundary with an emoji, exact stored HTML, the preview decode, escaping, ownership, `folder_id` null, the view reset with non default state, the failure path, and the Trash refusal pass. → AC-2 · AC-3 · AC-6 · AC-7 · AC-8 · AC-9 · AC-11 · AC-12
- [ ] `php artisan test --compact tests/Feature/NotesTest.php` → the existing note list and preview behavior still pass after the entity decode. → AC-11 · AC-12
- [ ] `php artisan test --compact` → full suite stays green. → AC-2 · AC-8 · AC-12
- [ ] `vendor/bin/pint --dirty --format agent` → no style changes remain on the touched files. → AC-1

## Acceptance criteria coverage

AC-1 is covered by the first UI step and the build command. AC-2 is covered by the second, ninth, thirteenth, and the focused tests. AC-3 is covered by the second, fourth, and fifth steps. AC-4 is covered by the tenth step. AC-5 is covered by the twelfth step and the build command. AC-6 is covered by the eleventh and sixteenth steps and the focused tests. AC-7 is covered by the eighth step and the focused tests. AC-8 is covered by the second and third steps. AC-9 is covered by the seventh and fifteenth steps. AC-10 is covered by the first step. AC-11 is covered by the sixth step and the focused tests. AC-12 is covered by the sixth, thirteenth, and fourteenth steps and the focused tests.

AC-4, AC-5, AC-7's disabled interface, AC-10, and the double submit check are manual, because they depend on the browser. The rest are automated.
