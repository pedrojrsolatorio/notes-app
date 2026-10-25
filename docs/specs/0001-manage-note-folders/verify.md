# Verify: Manage Note Folders · spec 0001 · updated 2026-10-25

_Steps derived from spec 0001 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual

- [ ] Create a folder while a note in another folder has unsaved edits. Confirm the new folder is selected and the note editor and edits remain available. → AC-1
- [ ] Rename the selected folder. Confirm it remains selected, the name changes, its notes stay assigned, and the folder list reorders. → AC-2
- [ ] Try a whitespace only name, a name with 256 Unicode code points, and a same account name that differs only by capitalization. Confirm each error stays inline and keeps your input. Confirm 255 code points is accepted. → AC-3
- [ ] Create a folder with surrounding Unicode whitespace. Confirm the display name is trimmed, capitalization is retained, and an equivalent name is allowed for a different account. → AC-3
- [x] Cause a duplicate insert after validation in the automated test. Confirm the database conflict stays inline and no success toast appears. → AC-3
- [ ] Delete a folder with a note and attachment. Confirm the warning explains permanent removal, the note becomes unfiled, its content and attachment remain, and All Notes is selected. → AC-4
- [ ] Try renaming and deleting another account's folder. Confirm both actions return not found and leave the folder unchanged. Confirm the notes page still requires sign in and email verification. → AC-5
- [ ] Create a new account and confirm it has Work, School, Personal, and Projects. Rename or delete a starter folder, create an empty folder, and confirm names sort by their normalized key. → AC-6
- [x] Run the collision migration test. Confirm colliding existing names stop the migration before schema or row changes. → AC-7
- [ ] Use the folder controls with a keyboard at 320 CSS pixels wide and at a wider viewport. Confirm menus, dialogs, and validation remain usable. → AC-8
- [ ] Sign in as one account and confirm the rendered folder names and IDs come only from that account's folders. → AC-5 · Value source 1
- [ ] Add names with different capitalization and accents. Confirm ordering uses the exact normalized key. → AC-6 · Value source 2
- [ ] Enter a name with surrounding Unicode whitespace. Confirm the saved visible name is the trimmed dialog input. → AC-3 · Value source 3
- [ ] Create a folder and confirm its owner comes from the signed in account, not a browser supplied owner ID. → AC-5 · Value source 4
- [ ] Create a folder and confirm the stored key is derived from the trimmed name, not supplied by the browser. → AC-3 · Value source 5
- [ ] Create a folder and confirm its returned database ID becomes the selected folder ID. → AC-1 · Value source 6
- [ ] Try a foreign folder ID for rename and confirm the target is resolved only through the signed in account's folders. → AC-5 · Value source 7
- [ ] Try a foreign folder ID for delete and confirm the target is resolved only through the signed in account's folders. → AC-5 · Value source 8
- [ ] Delete a folder with notes and confirm the existing foreign key behavior sets their folder ID to null and they appear in All Notes. → AC-4 · Value source 9

## Commands

- [x] `php artisan test --compact tests/Feature/NotesTest.php` → folder creation, rename, delete, ownership, uniqueness, and migration tests pass. → AC-1 through AC-7
- [x] `composer types:check` → no PHPStan errors. → AC-3 · AC-5 · AC-7

## Acceptance criteria coverage

AC-1 is covered by the first and sixteenth UI steps. AC-2 is covered by the second step. AC-3 is covered by the third through fifth and thirteenth and fifteenth steps. AC-4 is covered by the sixth and nineteenth steps. AC-5 is covered by the seventh and eleventh, fourteenth, seventeenth, and eighteenth steps. AC-6 is covered by the eighth and twelfth steps. AC-7 is covered by the ninth step. AC-8 is covered by the tenth step.
