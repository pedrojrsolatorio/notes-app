# 0001. Manage Note Folders

**Date**: 2026-10-24
**Status**: In Progress

## Summary

This feature lets you create, rename, and delete your folders from the notes screen. It extends the folder model already in use, keeps notes safe when a folder is removed, and prevents confusing names that differ only by capitalization. The build adds a normalized name key (a private value used to compare folder names consistently) and connects each folder action to the existing Livewire screen.

## Requirements

**User stories**:
- As a notes app user, I want to create and manage folders so that my notes reflect how I organize my work.

**Acceptance criteria** (the contract, each criterion is independently checkable):
- **AC-1**: From the notes screen, you can create an empty folder. A successful creation selects the new folder without changing the open note's folder, and its editor and unsaved edits remain available even when the note is outside the selected folder's filtered list.
- **AC-2**: You can rename a folder from its row actions. Renaming the selected folder keeps it selected, updates its displayed name, and leaves its notes assigned to it.
- **AC-3**: Folder names have surrounding Unicode whitespace removed, retain the capitalization you entered, contain at least one non whitespace character, and are no longer than 255 Unicode code points after trimming. Names are unique by their Unicode lowercase form within one account. Invalid names and database uniqueness conflicts appear inline in the open dialog, without losing the entered value or showing a success message.
- **AC-4**: Deleting a folder requires confirmation that explains the folder will be permanently removed and its notes will remain unfiled. Notes, their contents, and their attachments remain. Deleting the selected folder returns the notes view to All Notes.
- **AC-5**: Folder actions only affect folders owned by the signed in user. A folder ID from another account is treated as not found. The current route continues to require authentication and email verification.
- **AC-6**: The four starter folders, Work, School, Personal, and Projects, continue to be created for new accounts as defined in `app/Observers/UserObserver.php`, and can later be renamed or deleted. Empty folders are allowed, there is no product level folder count limit, and the sidebar sorts names by the normalized key without regard to capitalization.
- **AC-7**: Existing folder names are backfilled to the normalized name key. If existing folders for one account would collide under the new comparison rule, the migration stops before changing schema or data and does not rename or merge any folders.
- **AC-8**: Folder creation, renaming, deletion, dialogs, and validation feedback are usable with a keyboard and remain usable from 320 CSS pixels wide and up.

## Decision

**Chosen option**: Option 1: Extend the existing flat folder system.

Add folder management to the existing notes screen. Preserve the current Folder and Note relationships, and enforce per account case insensitive uniqueness with a server generated normalized name key and a database unique constraint.

**Implementation skills**: `laravel-best-practices` (`pedrojrsolatorio/notes-app`, `.agents/skills/laravel-best-practices/`) · `livewire-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/livewire-development/`) · `fluxui-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/fluxui-development/`) · `tailwindcss-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/tailwindcss-development/`) · `testing-best-practices` (`pedrojrsolatorio/notes-app`, `.agents/skills/testing-best-practices/`)

## Feature design

**Data model sketch**:

| Entity | Fields | Relationships and constraints |
|---|---|---|
| User | Existing user fields | One user has many folders and many notes. |
| Folder | `id` primary key, `user_id` required foreign key, `name` required string up to 255 Unicode code points, `name_key` required internal string up to 510 Unicode code points, existing timestamps | Belongs to one user and has many notes. Unique constraint on `user_id` and `name_key` using exact key comparison. User deletion cascades to folders. |
| Note | Existing fields including nullable `folder_id` | Belongs to one user and zero or one folder. Folder deletion sets `folder_id` to null. No note fields change. |

`name` keeps the user's capitalization after surrounding Unicode whitespace is removed. `name_key` is derived on the server from that name using Unicode lowercase conversion. It is not accepted from the browser. Two names are equal for this feature only when their trimmed lowercase forms match. Other Unicode normalization, such as treating composed and decomposed accents as equal, is not part of this decision. Store and compare the key exactly, so accents are not accidentally treated as equivalent by a database collation. Use the application's configured `DB_CONNECTION` for deployment and SQLite for automated tests. Verify the exact comparison and index behavior on the deployment connection.

**Interface behavior**:
- Place a create control beside the Folders heading.
- Use a dialog to create or rename. The rename dialog starts with the current name. Keep validation feedback next to the name field and retain the entered value.
- Put rename and delete actions in a menu on each folder row. Confirm deletion and explain that the notes will remain in All Notes.
- Show the existing success toast pattern after a successful action. Do not show a success toast after validation or database errors.
- Keep the existing note editor open when folder creation changes the selected folder. Preserve its unsaved form and editor content.
- Use the project's Flux modal and dropdown components, with keyboard operation and responsive behavior.

**API surface**:

| Surface | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| Existing notes page | `GET /notes` | Authenticated session | Folder list sorted by the exact normalized key, note list, selected folder and note | Existing `auth` and `verified` middleware | Authentication or verification redirect |
| Create folder | Livewire action | `name` string | New folder, selected folder ID, updated sidebar, success toast | Current user from the authenticated session | Inline name validation; database duplicate becomes the same validation error |
| Rename folder | Livewire action | Folder ID and `name` string | Updated folder name, preserved selection, updated sidebar, success toast | Folder is found through current user's folders | Not found for foreign or missing ID; inline name validation |
| Delete folder | Livewire action | Folder ID | Permanently deleted folder, unfiled notes, All Notes selected, success toast | Folder is found through current user's folders | Not found for foreign or missing ID |

No new route, public API, external service, or configuration value is needed.

**Value sourcing**:

| Action | Value produced or displayed | Source |
|---|---|---|
| Render folders | Folder name and ID | Current user's folders |
| Render folder order | Exact normalized key order | `name_key`, compared exactly by the configured database |
| Create folder | Visible name | Dialog input, trimmed on the server |
| Create folder | Owner ID | Authenticated user, never a browser input |
| Create or rename folder | Normalized comparison key | Derived on the server from the trimmed name |
| Create folder | Selected folder ID | ID returned by the created Folder record |
| Rename folder | Target folder and owner | Folder ID input resolved through the authenticated user's folders |
| Delete folder | Target folder and owner | Folder ID input resolved through the authenticated user's folders |
| Delete folder | Notes shown in All Notes | Existing notes whose `folder_id` is set to null by the foreign key |

**Key invariants**:
- The server derives `name_key` by trimming surrounding Unicode whitespace and applying Unicode lowercase conversion; a client cannot set or override it.
- Folder ownership always comes from the authenticated user's relationship.
- A folder name is unique by exact normalized key for its owner. The database constraint uses exact key comparison and is the final guard when requests race.
- Folder deletion removes only the Folder record. Notes and attachments remain.
- Starter folders remain seeded for new accounts, but have no special protection after creation.
- Create and rename use the same normalization and validation rules.

**Security model**:

Only an authenticated and verified account can use the existing notes page. Every create, rename, and delete operation is scoped to that account's folders. Actions accept no owner ID. A foreign or missing folder ID returns not found, without disclosing another account's data. There is no sharing, administrator override, or public folder access.

**Configuration required**:

None.

**Critical test scenarios**:
- Happy path: create an empty folder, select it, and retain an open note's unsaved content, verifies **AC-1**, **AC-6**, **AC-8**.
- Happy path: rename a folder, preserve its selection and note assignments, and verify case insensitive ordering, verifies **AC-2**, **AC-6**.
- Failure case: reject whitespace only, overlong, and same account case only duplicate names; preserve dialog input and show inline errors, verifies **AC-3**.
- Failure case: simulate a duplicate detected by the database constraint after validation, return the inline duplicate error and show no success toast, verifies **AC-3**.
- Happy path: delete a folder with notes and attachments, confirm the notes remain unfiled and intact, verifies **AC-4**.
- Auth and permission: attempt to rename or delete a folder owned by another account and receive not found with no data change, verifies **AC-5**.
- Migration failure: seed names that collide after normalization and verify migration stops before changing rows or schema, verifies **AC-7**.
- Accessibility: operate folder controls and validation with a keyboard at narrow and wide viewport sizes, verifies **AC-8**.

## Build plan

The Tracer Bullet approach starts with the smallest complete thread, then adds the remaining folder actions. The migration and shared name rule come first because create and rename both depend on them.

1. Add the normalized name key migration, check for collisions before schema or data changes, backfill existing folders, and add the per user unique constraint. Derive the key on server writes and preserve the existing display name. Satisfies **AC-3**, **AC-7**.
2. Build folder creation end to end with its sidebar entry, dialog, inline validation, selection behavior, and focused feature tests. Satisfies **AC-1**, **AC-3**, **AC-5**, **AC-6**, **AC-8**.
3. Build folder renaming end to end with its row action, prefilled dialog, selection preservation, alphabetical refresh, validation, and focused feature tests. Satisfies **AC-2**, **AC-3**, **AC-5**, **AC-6**, **AC-8**.
4. Build confirmed permanent deletion end to end, preserve notes and attachments as unfiled, return the selected view to All Notes, and add focused feature tests. Satisfies **AC-4**, **AC-5**, **AC-8**.

## Migration plan

**Strategy**: One migration during a brief maintenance window.

**Phases**:
1. Before schema or row changes, find folders that would share the same trimmed lowercase key for one user. If any exist, stop and report an actionable migration error. Do not rename or merge them.
2. During maintenance, add the key as nullable, backfill existing rows, and confirm that all rows have a key and no owner has duplicate keys. Make the key required, add the unique constraint on owner and key, then remove the now redundant exact name constraint. Deploy the application that derives the key for all folder writes before reopening the app.

**Rollback**: Revert the application and migration. The original folder names and note assignments remain unchanged; the migration removes the key and restores the prior exact name constraint.

**Risks**: The migration cannot complete until any conflicting existing names are manually resolved. A maintenance window avoids old application code creating folders without a key while the constraint is being installed.

## Consequences

**Positive**:
- You can manage folders without leaving the notes screen.
- Folder deletion cannot delete note content or attachments.
- Duplicate name behavior is consistent across accounts and database text collations.
- Existing folder assignment remains intact.

**Negative / tradeoffs**:
- The migration adds and backfills an internal column, and a collision blocks deployment until it is resolved.
- Every folder write must keep the visible name and normalized key in sync.
- Deleted folders cannot be restored, though their notes remain available in All Notes.

**Neutral**:
- Folder names remain flat and alphabetically ordered.
- Tags, pinning, nested folders, and custom ordering remain outside this feature.

## Follow-up

- [ ] `laravel-best-practices` conventions are not yet listed in root `AGENTS.md` `## Agent skills`; add the skill pointer and the project wide conventions there.
- [ ] `livewire-development` conventions are not yet listed in root `AGENTS.md` `## Agent skills`; add the skill pointer and the project wide conventions there.
- [ ] `fluxui-development` conventions are not yet listed in root `AGENTS.md` `## Agent skills`; add the skill pointer and the project wide conventions there.
- [ ] `tailwindcss-development` conventions are not yet listed in root `AGENTS.md` `## Agent skills`; add the skill pointer and the project wide conventions there.
- [ ] `testing-best-practices` conventions are not yet listed in root `AGENTS.md` `## Agent skills`; add the skill pointer and the project wide conventions there.

## Rationale

See [rationale.md](./rationale.md) for the context, options, and reasons behind this decision.
