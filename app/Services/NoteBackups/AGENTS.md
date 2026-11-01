# Note backup services

This area implements encrypted note backup export, preview, and additive restore. Follow the [feature spec](../../../docs/specs/0003-note-import-export.md).

## Files

1. `NoteBackupService.php` coordinates record export, archive validation, preview, and restore.
2. `BackupArchive.php` creates and reads the encrypted outer ZIP and its inner payload.
3. `StrictJsonDecoder.php` rejects duplicate JSON keys before decoding the manifest.
4. `NoteBackupHtmlSanitizer.php` cleans imported note HTML using the `note_backup` purifier profile.
5. `NoteBackupPreviewStore.php` stages per user previews and removes expired previews and abandoned workspaces.
6. `NoteBackupException.php` carries the response status, reason code, and item details.

The HTTP endpoints live in `app/Http/Controllers/NoteBackupController.php` and `routes/settings.php`. Limits live in `config/import_export.php`. The cleanup command is scheduled in `routes/console.php`.

## Safety constraints

1. Keep note, folder, and attachment queries scoped to the signed in user's relationships.
2. Keep the outer archive to its documented format and encrypt the full inner archive, including attachment bytes. The format uses Argon2id and libsodium secretstream.
3. Validate archive entry names, JSON structure, timestamps, attachment metadata, and configured size limits before restoring any records.
4. Sanitize imported note HTML while preserving the editor markup allowed by the purifier profile.
5. Restore records in a database transaction. If it fails, remove attachment files created by that restore.
6. Keep staged archives and temporary workspaces on the private local disk, and preserve preview expiry and cleanup behavior.
7. Coordinate format or security changes with the feature spec and regression tests.

## Checks

Run `php artisan test --compact tests/Feature/NoteBackupTest.php` and `composer types:check` after changes in this area.

## Agent skills

Declined: `mews/purifier` Agent Skill and MCP discovery.

_Drafted by /sync from the introducing change, worth a quick human pass._
