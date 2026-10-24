# Rationale: Manage Note Folders

## Context

The notes screen can already assign a note to one folder, filter notes by folder, and display the user's folders. New accounts receive four starter folders. There is no interface for creating, renaming, or deleting folders, so the existing list cannot adapt as a person's needs change.

Folders belong to one user. Notes can have no folder or one folder, and deleting a folder already leaves its notes in place. The feature must preserve those relationships, keep each account's data separate, and make duplicate names predictable across supported database connections. The app has no team sharing or folder recovery workflow.

## Options considered

### Option 1: Extend the existing flat folder system

Add folder creation, renaming, and deletion to the current notes screen. Keep the existing Folder and Note relationships, and add a normalized key for reliable case insensitive uniqueness.

**Pros**:
- Reuses the current folder list, ownership relationship, and note assignment.
- Keeps folder actions in the place where notes are already organized.
- Does not introduce a second way to group notes.

**Cons**:
- Requires a schema migration and a one time backfill.
- A preexisting name collision must be resolved before the migration can finish.

### Option 2: Add a parallel tag system

Keep folders as they are and add tags that can group notes across folders.

**Pros**:
- One note could be grouped in several ways without changing its folder.
- Tags could support flexible search across the collection.

**Cons**:
- Adds a separate many to many relationship and another interface to maintain.
- Does not solve the immediate need to create, rename, or delete folders.

### Option 3: Replace flat folders with nested folders

Change folders into a hierarchy, allowing folders to contain subfolders.

**Pros**:
- Supports more detailed organization for a large collection.
- Can represent a hierarchy some users already understand.

**Cons**:
- Adds parent relationships, navigation, and deeper deletion rules.
- Is more work than needed for the current personal use goal.

## Rationale

The current folder model already expresses the needed ownership and note assignment rules. Extending it avoids migrating notes to a new organization system, and the existing `nullOnDelete` relationship means folder removal can preserve note content and attachments. A normalized key makes the chosen duplicate rule consistent even when a database's default text comparison differs.

Use Flux dialogs and row menus for the folder controls, since Flux is already part of the app and supplies the interaction patterns needed for keyboard accessible dialogs. The runner up is custom Blade markup, which would keep the visual style close to the current notes list but require more custom focus and keyboard behavior.

Tags would answer a broader organization need, but they add another data relationship and do not provide folder management. Nested folders add parent and deletion behavior without a stated need. A full replacement would add migration risk while discarding working behavior.

## References

No external references were used.
