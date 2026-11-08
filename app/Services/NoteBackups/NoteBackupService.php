<?php

namespace App\Services\NoteBackups;

use App\Concerns\AttachmentValidationRules;
use App\Models\Folder;
use App\Models\Note;
use App\Models\NoteAttachment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;
use ZipArchive;

class NoteBackupService
{
    use AttachmentValidationRules;

    public function __construct(
        private BackupArchive $archive,
        private NoteBackupPreviewStore $previewStore,
        private NoteBackupHtmlSanitizer $sanitizer,
    ) {}

    public function export(User $user, string $password): string
    {
        $workspace = $this->createWorkspace();
        $outputPath = Storage::disk('local')->path('note-backups/exports/'.Str::uuid().'.zip');
        FileFacade::ensureDirectoryExists(dirname($outputPath), 0700, true);

        if (! is_dir(dirname($outputPath)) || ! is_writable(dirname($outputPath))) {
            $this->removeWorkspace($workspace);

            throw new NoteBackupException('The backup could not be prepared.', 500, 'storage_error');
        }
        $innerOpened = false;
        $created = false;
        $innerPath = $workspace.'/inner.zip';
        $inner = new ZipArchive;

        try {
            if ($inner->open($innerPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new NoteBackupException('The backup could not be prepared.', 500, 'storage_error');
            }

            $innerOpened = true;
            $manifestPath = $this->writeExportManifest($user, $workspace, $inner);

            if (! $inner->addFile($manifestPath, 'manifest.json') || ! $inner->close()) {
                throw new NoteBackupException('The backup could not be finalized.', 500, 'storage_error');
            }
            $innerOpened = false;

            $this->archive->createEncryptedArchive($innerPath, $outputPath, $password);
            $created = true;

            return $outputPath;
        } finally {
            if ($innerOpened) {
                $inner->close();
            }

            if (! $created && is_file($outputPath) && ! unlink($outputPath)) {
                Log::warning('A failed note backup export could not be removed.', ['path' => $outputPath]);
            }

            $this->removeWorkspace($workspace);
        }
    }

    /**
     * @return array{preview_token: string, expires_at: string, counts: array<string, array<string, int>>}
     */
    public function preview(User $user, UploadedFile $uploadedArchive, string $password): array
    {
        $archivePath = $uploadedArchive->getRealPath();
        $archiveSize = $uploadedArchive->getSize();

        if ($archivePath === false || ! is_file($archivePath) || ! is_readable($archivePath)) {
            throw new NoteBackupException('The uploaded backup could not be read.', 400, 'invalid_archive');
        }

        if (! is_int($archiveSize) || $archiveSize > config('import_export.max_archive_bytes')) {
            throw new NoteBackupException('The backup exceeds the supported size limit.', 413, 'size_exceeded');
        }

        $workspace = $this->createWorkspace();

        try {
            $backup = $this->inspectArchive($archivePath, $password, $workspace);
            $counts = $this->previewCounts($user, $backup['manifest']);
            $token = $this->previewStore->stage((int) $user->getKey(), $uploadedArchive);

            return [
                'preview_token' => $token,
                'expires_at' => $this->previewStore->expiresAt((int) $user->getKey(), $token),
                'counts' => $counts,
            ];
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * @return array{counts: array<string, array<string, int>>, skipped_items: list<array{entity_type: string, display_name: string, reason_code: string}>}
     */
    public function restore(User $user, string $token, string $password): array
    {
        $archivePath = $this->previewStore->resolve((int) $user->getKey(), $token);
        $workspace = null;

        try {
            $workspace = $this->createWorkspace();
            $backup = $this->inspectArchive($archivePath, $password, $workspace);
            $createdFiles = [];
            $committed = false;

            try {
                $result = DB::transaction(function () use ($user, $backup, &$createdFiles): array {
                    return $this->restoreRecords($user, $backup, $createdFiles);
                });
                $committed = true;

                return $result;
            } finally {
                if (! $committed) {
                    $this->removeCreatedFiles($createdFiles);
                }
            }
        } finally {
            if ($workspace !== null) {
                $this->removeWorkspace($workspace);
            }

            $this->previewStore->delete((int) $user->getKey(), $token);
        }
    }

    /**
     * @param  array{manifest: array<string, mixed>, attachment_paths: array<string, string>}  $backup
     * @param  list<string>  $createdFiles
     * @return array{counts: array<string, array<string, int>>, skipped_items: list<array{entity_type: string, display_name: string, reason_code: string}>}
     */
    private function restoreRecords(User $user, array $backup, array &$createdFiles): array
    {
        $manifest = $backup['manifest'];
        $folderMatches = $this->findExistingFolders($user, $manifest['folders'], lock: true);
        $existingFoldersByPortableId = $folderMatches['by_portable_id'];
        $existingFoldersByName = $folderMatches['by_name_key'];
        $folderIdByPortableId = [];
        $createdFolderIdByName = [];
        $counts = [
            'folders' => ['created' => 0, 'reused' => 0, 'skipped' => 0],
            'notes' => ['created' => 0, 'reused' => 0, 'skipped' => 0],
            'attachments' => ['created' => 0, 'reused' => 0, 'skipped' => 0],
        ];
        $skippedItems = [];

        foreach ($manifest['folders'] as $folderData) {
            $portableId = $folderData['portable_id'];
            $nameKey = Folder::nameKeyFor(Folder::normalizeName($folderData['name']));
            $existingFolder = $existingFoldersByPortableId[$portableId]
                ?? $existingFoldersByName[$nameKey]
                ?? null;

            if ($existingFolder !== null) {
                $folderIdByPortableId[$portableId] = (int) $existingFolder->getKey();
                $counts['folders']['reused']++;

                continue;
            }

            if (isset($createdFolderIdByName[$nameKey])) {
                $folderIdByPortableId[$portableId] = $createdFolderIdByName[$nameKey];
                $counts['folders']['reused']++;

                continue;
            }

            $folder = $user->folders()->make([
                'name' => $folderData['name'],
                'portable_id' => $portableId,
            ]);
            $folder->timestamps = false;
            $folder->created_at = $this->parseTimestamp($folderData['created_at']);
            $folder->updated_at = $this->parseTimestamp($folderData['updated_at']);
            $folder->save();
            $folderIdByPortableId[$portableId] = (int) $folder->getKey();
            $createdFolderIdByName[$nameKey] = (int) $folder->getKey();
            $counts['folders']['created']++;
        }

        $existingNotes = $this->findExistingNotes(
            $user,
            array_column($manifest['notes'], 'portable_id'),
            lock: true,
        );
        $noteByPortableId = $existingNotes;

        foreach ($manifest['notes'] as $noteData) {
            if (isset($existingNotes[$noteData['portable_id']])) {
                $counts['notes']['skipped']++;
                $skippedItems[] = [
                    'entity_type' => 'note',
                    'display_name' => $this->displayName($noteData['title']),
                    'reason_code' => 'note_already_exists',
                ];

                continue;
            }

            $note = $user->notes()->make([
                'folder_id' => $noteData['folder_portable_id'] === null
                    ? null
                    : $folderIdByPortableId[$noteData['folder_portable_id']],
                'title' => $noteData['title'],
                'content' => $noteData['content'],
                'portable_id' => $noteData['portable_id'],
            ]);
            $note->timestamps = false;
            $note->created_at = $this->parseTimestamp($noteData['created_at']);
            $note->updated_at = $this->parseTimestamp($noteData['updated_at']);
            $note->setAttribute('deleted_at', $noteData['deleted_at'] === null
                ? null
                : $this->parseTimestamp($noteData['deleted_at']));
            $note->save();
            $noteByPortableId[$noteData['portable_id']] = $note;
            $counts['notes']['created']++;
        }

        $existingAttachments = $this->findExistingAttachments(
            $user,
            array_column($manifest['attachments'], 'portable_id'),
            lock: true,
        );

        foreach ($manifest['attachments'] as $attachmentData) {
            if (isset($existingAttachments[$attachmentData['portable_id']])) {
                $counts['attachments']['skipped']++;
                $skippedItems[] = [
                    'entity_type' => 'attachment',
                    'display_name' => $this->displayName($attachmentData['original_name']),
                    'reason_code' => 'portable_id_exists',
                ];

                continue;
            }

            $note = $noteByPortableId[$attachmentData['note_portable_id']];
            $sourcePath = $backup['attachment_paths'][$attachmentData['portable_id']];
            $relativePath = $this->installAttachment($user, $sourcePath, $createdFiles);
            $attachment = $note->attachments()->make([
                'user_id' => $user->getKey(),
                'original_name' => $attachmentData['original_name'],
                'file_path' => $relativePath,
                'mime_type' => $attachmentData['mime_type'],
                'file_size' => $attachmentData['file_size'],
                'portable_id' => $attachmentData['portable_id'],
            ]);
            $attachment->timestamps = false;
            $attachment->created_at = $this->parseTimestamp($attachmentData['created_at']);
            $attachment->updated_at = $attachment->created_at;
            $attachment->save();
            $counts['attachments']['created']++;
        }

        return [
            'counts' => $counts,
            'skipped_items' => $skippedItems,
        ];
    }

    private function parseTimestamp(string $timestamp): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $timestamp,
            'UTC',
        );

        if ($parsed === null) {
            throw new NoteBackupException('A backup timestamp is invalid.', 400, 'invalid_timestamp');
        }

        return $parsed;
    }

    /**
     * @param  list<string>  $createdFiles
     */
    private function installAttachment(User $user, string $sourcePath, array &$createdFiles): string
    {
        $directory = 'note-attachments/'.$user->getKey();
        $disk = Storage::disk('local');

        if (! $disk->directoryExists($directory) && ! $disk->makeDirectory($directory)) {
            throw new NoteBackupException('The attachment storage directory could not be prepared.', 500, 'storage_error');
        }

        $relativePath = $directory.'/'.Str::uuid();
        $source = fopen($sourcePath, 'rb');

        if ($source === false) {
            throw new NoteBackupException('A validated attachment could not be read.', 500, 'storage_error');
        }

        $createdFiles[] = $relativePath;

        try {
            if (! $disk->writeStream($relativePath, $source)) {
                throw new NoteBackupException('An attachment could not be installed.', 500, 'storage_error');
            }
        } finally {
            fclose($source);
        }

        return $relativePath;
    }

    /**
     * @param  list<string>  $createdFiles
     */
    private function removeCreatedFiles(array $createdFiles): void
    {
        $disk = Storage::disk('local');

        foreach ($createdFiles as $path) {
            if ($disk->exists($path) && ! $disk->delete($path)) {
                Log::warning('A failed note backup restore left an attachment file behind.', ['path' => $path]);
            }
        }
    }

    /**
     * @return array{manifest: array<string, mixed>, attachment_paths: array<string, string>}
     */
    private function inspectArchive(string $archivePath, string $password, string $workspace): array
    {
        $innerPath = $this->archive->decryptOuterArchive($archivePath, $password, $workspace);
        $backup = $this->archive->extractInnerArchive($innerPath, $workspace);
        $this->validateManifest($backup['manifest'], $backup['attachment_paths']);

        foreach ($backup['manifest']['notes'] as &$note) {
            $note['content'] = $this->sanitizer->clean($note['content']);
        }
        unset($note);

        return $backup;
    }

    private function writeExportManifest(User $user, string $workspace, ZipArchive $archive): string
    {
        $sectionPaths = [
            'folders' => $workspace.'/folders.json',
            'notes' => $workspace.'/notes.json',
            'attachments' => $workspace.'/attachments.json',
        ];
        $sectionStreams = [];
        $sectionFirst = array_fill_keys(array_keys($sectionPaths), true);
        $manifestBytes = strlen('{"format_version":1,"folders":[],"notes":[],"attachments":[]}');
        $recordCount = 0;
        $attachmentBytes = 0;

        try {
            foreach ($sectionPaths as $name => $path) {
                $sectionStreams[$name] = fopen($path, 'wb');

                if ($sectionStreams[$name] === false) {
                    throw new NoteBackupException('The backup could not be prepared.', 500, 'storage_error');
                }
            }

            $user->folders()
                ->orderBy('id')
                ->chunkById(200, function (EloquentCollection $folders) use (
                    &$recordCount,
                    &$manifestBytes,
                    &$sectionFirst,
                    $sectionStreams,
                ): void {
                    foreach ($folders as $folder) {
                        $recordCount++;
                        $this->ensureRecordLimit($recordCount);
                        $this->appendManifestRecord($sectionStreams['folders'], [
                            'portable_id' => (string) $folder->portable_id,
                            'name' => (string) $folder->name,
                            'created_at' => $this->formatTimestamp($folder->created_at),
                            'updated_at' => $this->formatTimestamp($folder->updated_at),
                        ], $sectionFirst['folders'], $manifestBytes);
                    }
                });

            $user->notes()
                ->withTrashed()
                ->with([
                    'folder' => fn ($query) => $query->where('user_id', $user->getKey()),
                    'attachments',
                ])
                ->orderBy('id')
                ->chunkById(100, function (EloquentCollection $notes) use (
                    $user,
                    $archive,
                    &$recordCount,
                    &$manifestBytes,
                    &$sectionFirst,
                    &$attachmentBytes,
                    $sectionStreams,
                ): void {
                    foreach ($notes as $note) {
                        if ($note->folder_id !== null && $note->folder === null) {
                            throw $this->invalidItem('note', (string) $note->title, 'The note folder is not owned by this account.', 'invalid_folder', 400);
                        }

                        $recordCount++;
                        $this->ensureRecordLimit($recordCount);
                        $this->appendManifestRecord($sectionStreams['notes'], [
                            'portable_id' => (string) $note->portable_id,
                            'folder_portable_id' => $note->folder?->portable_id,
                            'title' => (string) $note->title,
                            'content' => $this->sanitizer->clean($note->content),
                            'deleted_at' => $note->deleted_at === null
                                ? null
                                : $this->formatTimestamp($note->deleted_at),
                            'created_at' => $this->formatTimestamp($note->created_at),
                            'updated_at' => $this->formatTimestamp($note->updated_at),
                        ], $sectionFirst['notes'], $manifestBytes);

                        foreach ($note->attachments as $attachment) {
                            $recordCount++;
                            $this->ensureRecordLimit($recordCount);
                            $filePath = $this->resolveAttachmentFile($user, $attachment);
                            $fileSize = filesize($filePath);

                            if (! is_int($fileSize) || $fileSize > config('import_export.max_attachment_bytes')) {
                                throw $this->invalidItem('attachment', $attachment->original_name, 'The attachment exceeds the supported size limit.', 'size_exceeded', 413);
                            }

                            $this->validateAttachmentFile($filePath, $attachment->original_name);
                            $attachmentBytes += $fileSize;

                            if ($attachmentBytes + $manifestBytes > config('import_export.max_expanded_bytes')) {
                                throw new NoteBackupException('The backup exceeds the supported expanded size limit.', 413, 'size_exceeded');
                            }

                            $portableId = (string) $attachment->portable_id;
                            $entryPath = 'attachments/'.$portableId;

                            if (! $archive->addFile($filePath, $entryPath)) {
                                throw $this->invalidItem('attachment', $attachment->original_name, 'The attachment could not be added to the backup.', 'unreadable_attachment', 400);
                            }

                            $this->appendManifestRecord($sectionStreams['attachments'], [
                                'portable_id' => $portableId,
                                'note_portable_id' => (string) $note->portable_id,
                                'original_name' => (string) $attachment->original_name,
                                'mime_type' => $attachment->mime_type,
                                'file_size' => $fileSize,
                                'created_at' => $this->formatTimestamp($attachment->created_at),
                                'payload_path' => $entryPath,
                            ], $sectionFirst['attachments'], $manifestBytes);
                        }
                    }
                });

            foreach ($sectionStreams as $stream) {
                fclose($stream);
            }
            $sectionStreams = [];

            if ($manifestBytes > config('import_export.max_manifest_bytes')) {
                throw new NoteBackupException('The backup manifest exceeds the supported size limit.', 413, 'size_exceeded');
            }

            if ($attachmentBytes + $manifestBytes > config('import_export.max_expanded_bytes')) {
                throw new NoteBackupException('The backup exceeds the supported expanded size limit.', 413, 'size_exceeded');
            }

            $manifestPath = $workspace.'/manifest.json';
            $manifest = fopen($manifestPath, 'wb');

            if ($manifest === false) {
                throw new NoteBackupException('The backup manifest could not be prepared.', 500, 'storage_error');
            }

            try {
                $this->writeAll($manifest, '{"format_version":1,"folders":[');
                $this->copyFileToStream($sectionPaths['folders'], $manifest);
                $this->writeAll($manifest, '],"notes":[');
                $this->copyFileToStream($sectionPaths['notes'], $manifest);
                $this->writeAll($manifest, '],"attachments":[');
                $this->copyFileToStream($sectionPaths['attachments'], $manifest);
                $this->writeAll($manifest, ']}');
            } finally {
                fclose($manifest);
            }

            $actualManifestBytes = filesize($manifestPath);

            if ($actualManifestBytes === false || $actualManifestBytes > config('import_export.max_manifest_bytes')) {
                throw new NoteBackupException('The backup manifest exceeds the supported size limit.', 413, 'size_exceeded');
            }

            return $manifestPath;
        } catch (JsonException $exception) {
            throw new NoteBackupException('A note could not be encoded in the backup.', 400, 'invalid_note', previous: $exception);
        } finally {
            foreach ($sectionStreams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $attachmentPaths
     */
    private function validateManifest(array &$manifest, array $attachmentPaths): void
    {
        $keys = array_keys($manifest);
        $expectedKeys = ['format_version', 'folders', 'notes', 'attachments'];
        sort($keys);
        sort($expectedKeys);

        if ($keys !== $expectedKeys
            || ($manifest['format_version'] ?? null) !== 1
            || ! is_array($manifest['folders'] ?? null)
            || ! array_is_list($manifest['folders'])
            || ! is_array($manifest['notes'] ?? null)
            || ! array_is_list($manifest['notes'])
            || ! is_array($manifest['attachments'] ?? null)
            || ! array_is_list($manifest['attachments'])) {
            throw new NoteBackupException('The backup manifest is invalid.', 400, 'invalid_manifest');
        }

        $recordCount = count($manifest['folders']) + count($manifest['notes']) + count($manifest['attachments']);

        if ($recordCount > config('import_export.max_records')) {
            throw new NoteBackupException('The backup contains too many records.', 413, 'size_exceeded');
        }

        $errors = [];
        $folderIds = [];
        $noteIds = [];
        $attachmentIds = [];
        $folderKeys = ['portable_id', 'name', 'created_at', 'updated_at'];
        $noteKeys = [
            'portable_id', 'folder_portable_id', 'title', 'content', 'deleted_at', 'created_at', 'updated_at',
        ];
        $attachmentKeys = [
            'portable_id', 'note_portable_id', 'original_name', 'mime_type', 'file_size', 'created_at', 'payload_path',
        ];

        foreach ($manifest['folders'] as &$folder) {
            if (! is_array($folder) || ! $this->hasExactKeys($folder, $folderKeys)) {
                $errors[] = $this->invalidItem('folder', 'Folder', 'The folder entry has an invalid shape.', 'invalid_folder')->details[0];

                continue;
            }

            $displayName = is_string($folder['name']) ? $folder['name'] : 'Folder';
            $normalizedName = is_string($folder['name'])
                ? Folder::normalizeName($folder['name'])
                : '';

            if (! $this->isUuidV4($folder['portable_id'])
                || ! is_string($folder['name'])
                || $normalizedName === ''
                || mb_strlen($normalizedName) > 255
                || ! $this->isValidTimestamp($folder['created_at'])
                || ! $this->isValidTimestamp($folder['updated_at'])) {
                $errors[] = $this->invalidItem('folder', $displayName, 'The folder entry contains invalid data.', 'invalid_folder')->details[0];

                continue;
            }

            $folder['portable_id'] = strtolower($folder['portable_id']);

            if (isset($folderIds[$folder['portable_id']])) {
                $errors[] = $this->invalidItem('folder', $displayName, 'The folder ID is repeated in the backup.', 'duplicate_portable_id')->details[0];
            }

            $folderIds[$folder['portable_id']] = true;
        }
        unset($folder);

        foreach ($manifest['notes'] as &$note) {
            if (! is_array($note) || ! $this->hasExactKeys($note, $noteKeys)) {
                $errors[] = $this->invalidItem('note', 'Note', 'The note entry has an invalid shape.', 'invalid_note')->details[0];

                continue;
            }

            $displayName = is_string($note['title']) ? $note['title'] : 'Note';
            $folderIdIsValid = $note['folder_portable_id'] === null || $this->isUuidV4($note['folder_portable_id']);
            $deletedAtIsValid = $note['deleted_at'] === null || $this->isValidTimestamp($note['deleted_at']);
            $contentIsValid = $note['content'] === null || is_string($note['content']);

            if (! $this->isUuidV4($note['portable_id'])
                || ! $folderIdIsValid
                || ! is_string($note['title'])
                || trim($note['title']) === ''
                || mb_strlen($note['title']) > 255
                || ! $contentIsValid
                || ! $deletedAtIsValid
                || ! $this->isValidTimestamp($note['created_at'])
                || ! $this->isValidTimestamp($note['updated_at'])) {
                $errors[] = $this->invalidItem('note', $displayName, 'The note entry contains invalid data.', 'invalid_note')->details[0];

                continue;
            }

            $note['portable_id'] = strtolower($note['portable_id']);
            $note['folder_portable_id'] = is_string($note['folder_portable_id'])
                ? strtolower($note['folder_portable_id'])
                : null;

            if (isset($noteIds[$note['portable_id']])) {
                $errors[] = $this->invalidItem('note', $displayName, 'The note ID is repeated in the backup.', 'duplicate_portable_id')->details[0];
            }

            $noteIds[$note['portable_id']] = true;
        }
        unset($note);

        foreach ($manifest['attachments'] as &$attachment) {
            if (! is_array($attachment) || ! $this->hasExactKeys($attachment, $attachmentKeys)) {
                $errors[] = $this->invalidItem('attachment', 'Attachment', 'The attachment entry has an invalid shape.', 'invalid_attachment')->details[0];

                continue;
            }

            $displayName = is_string($attachment['original_name']) ? $attachment['original_name'] : 'Attachment';
            $actualId = $attachment['portable_id'];
            $payloadPathIsValid = is_string($actualId) && $attachment['payload_path'] === 'attachments/'.$actualId;
            $mimeTypeIsValid = $attachment['mime_type'] === null
                || (is_string($attachment['mime_type']) && mb_strlen($attachment['mime_type']) <= 255);

            if (! $this->isUuidV4($actualId)
                || ! $this->isUuidV4($attachment['note_portable_id'])
                || ! is_string($attachment['original_name'])
                || trim($attachment['original_name']) === ''
                || mb_strlen($attachment['original_name']) > 255
                || ! $mimeTypeIsValid
                || ! is_int($attachment['file_size'])
                || $attachment['file_size'] < 0
                || $attachment['file_size'] > config('import_export.max_attachment_bytes')
                || ! $this->isValidTimestamp($attachment['created_at'])
                || ! $payloadPathIsValid) {
                $errors[] = $this->invalidItem('attachment', $displayName, 'The attachment entry contains invalid data.', 'invalid_attachment')->details[0];

                continue;
            }

            $attachment['portable_id'] = strtolower($actualId);
            $attachment['note_portable_id'] = strtolower($attachment['note_portable_id']);
            $attachment['payload_path'] = 'attachments/'.$attachment['portable_id'];

            if (isset($attachmentIds[$attachment['portable_id']])) {
                $errors[] = $this->invalidItem('attachment', $displayName, 'The attachment ID is repeated in the backup.', 'duplicate_portable_id')->details[0];
            }

            $attachmentIds[$attachment['portable_id']] = true;
        }
        unset($attachment);

        foreach ($manifest['notes'] as $note) {
            if (is_array($note) && is_string($note['portable_id'] ?? null)) {
                if (isset($note['folder_portable_id'])
                    && ! isset($folderIds[$note['folder_portable_id']])) {
                    $errors[] = $this->invalidItem(
                        'note',
                        is_string($note['title'] ?? null) ? $note['title'] : 'Note',
                        'The linked folder is missing from the backup.',
                        'missing_folder',
                    )->details[0];
                }
            }
        }

        foreach ($manifest['attachments'] as $attachment) {
            if (! is_array($attachment)
                || ! is_string($attachment['portable_id'] ?? null)
                || ! is_string($attachment['note_portable_id'] ?? null)
                || ! is_string($attachment['original_name'] ?? null)
                || ! is_int($attachment['file_size'] ?? null)) {
                continue;
            }

            if (! isset($noteIds[$attachment['note_portable_id']])) {
                $errors[] = $this->invalidItem(
                    'attachment',
                    $attachment['original_name'],
                    'The linked note is missing from the backup.',
                    'missing_note',
                )->details[0];

                continue;
            }

            $attachmentPath = $attachmentPaths[$attachment['portable_id']] ?? null;

            if (! is_string($attachmentPath) || ! is_file($attachmentPath)) {
                $errors[] = $this->invalidItem('attachment', $attachment['original_name'], 'The attachment data is missing from the backup.', 'missing_attachment')->details[0];

                continue;
            }

            $actualSize = filesize($attachmentPath);

            if ($actualSize !== $attachment['file_size']) {
                $errors[] = $this->invalidItem('attachment', $attachment['original_name'], 'The attachment size does not match the manifest.', 'invalid_attachment')->details[0];

                continue;
            }

            try {
                $this->validateAttachmentFile($attachmentPath, $attachment['original_name']);
            } catch (NoteBackupException $exception) {
                $errors[] = $exception->details[0];
            }
        }

        if (array_diff_key($attachmentPaths, $attachmentIds) !== [] || array_diff_key($attachmentIds, $attachmentPaths) !== []) {
            $errors[] = $this->invalidItem('attachment', 'Attachment', 'The attachment entries do not match the manifest.', 'unlinked_attachment')->details[0];
        }

        if ($errors !== []) {
            throw new NoteBackupException('The backup contains invalid items.', 400, 'invalid_items', $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expectedKeys
     */
    private function hasExactKeys(array $value, array $expectedKeys): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expectedKeys);

        return $keys === $expectedKeys;
    }

    private function isUuidV4(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value) === 1;
    }

    private function isValidTimestamp(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $timestamp = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();

        return $timestamp !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $timestamp->format('Y-m-d\TH:i:s.u\Z') === $value;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, array<string, int>>
     */
    private function previewCounts(User $user, array $manifest): array
    {
        $folderMatches = $this->findExistingFolders($user, $manifest['folders']);
        $existingFolderNames = $folderMatches['by_name_key'];
        $plannedFolderNames = [];
        $counts = [
            'folders' => ['created' => 0, 'reused' => 0, 'skipped' => 0],
            'notes' => ['created' => 0, 'reused' => 0, 'skipped' => 0],
            'attachments' => ['created' => 0, 'reused' => 0, 'skipped' => 0],
        ];

        foreach ($manifest['folders'] as $folder) {
            $portableId = $folder['portable_id'];
            $nameKey = Folder::nameKeyFor(Folder::normalizeName($folder['name']));

            if (isset($folderMatches['by_portable_id'][$portableId])
                || isset($existingFolderNames[$nameKey])
                || isset($plannedFolderNames[$nameKey])) {
                $counts['folders']['reused']++;
            } else {
                $counts['folders']['created']++;
                $plannedFolderNames[$nameKey] = true;
            }
        }

        $existingNotes = $this->findExistingNotes($user, array_column($manifest['notes'], 'portable_id'));

        foreach ($manifest['notes'] as $note) {
            $counts['notes'][isset($existingNotes[$note['portable_id']]) ? 'skipped' : 'created']++;
        }

        $existingAttachments = $this->findExistingAttachments($user, array_column($manifest['attachments'], 'portable_id'));

        foreach ($manifest['attachments'] as $attachment) {
            $counts['attachments'][isset($existingAttachments[$attachment['portable_id']]) ? 'skipped' : 'created']++;
        }

        return $counts;
    }

    /**
     * @param  list<array<string, mixed>>  $folders
     * @return array{by_portable_id: array<string, Folder>, by_name_key: array<string, Folder>}
     */
    private function findExistingFolders(User $user, array $folders, bool $lock = false): array
    {
        $byPortableId = [];
        $byNameKey = [];
        $portableIds = array_values(array_unique(array_column($folders, 'portable_id')));
        $nameKeys = array_values(array_unique(array_map(
            fn (array $folder): string => Folder::nameKeyFor(Folder::normalizeName($folder['name'])),
            $folders,
        )));

        foreach (array_chunk($portableIds, 500) as $chunk) {
            $query = $user->folders()->whereIn('portable_id', $chunk);

            if ($lock) {
                $query->lockForUpdate();
            }

            foreach ($query->get() as $folder) {
                $byPortableId[$folder->portable_id] = $folder;
            }
        }

        foreach (array_chunk($nameKeys, 500) as $chunk) {
            $query = $user->folders()->whereIn('name_key', $chunk);

            if ($lock) {
                $query->lockForUpdate();
            }

            foreach ($query->get() as $folder) {
                $byNameKey[$folder->name_key] = $folder;
            }
        }

        return ['by_portable_id' => $byPortableId, 'by_name_key' => $byNameKey];
    }

    /**
     * @param  list<string>  $portableIds
     * @return array<string, Note>
     */
    private function findExistingNotes(User $user, array $portableIds, bool $lock = false): array
    {
        $existing = [];

        foreach (array_chunk(array_values(array_unique($portableIds)), 500) as $chunk) {
            $query = $user->notes()->withTrashed()->whereIn('portable_id', $chunk);

            if ($lock) {
                $query->lockForUpdate();
            }

            foreach ($query->get() as $note) {
                $existing[$note->portable_id] = $note;
            }
        }

        return $existing;
    }

    /**
     * @param  list<string>  $portableIds
     * @return array<string, NoteAttachment>
     */
    private function findExistingAttachments(User $user, array $portableIds, bool $lock = false): array
    {
        $existing = [];

        foreach (array_chunk(array_values(array_unique($portableIds)), 500) as $chunk) {
            $query = NoteAttachment::query()
                ->where('user_id', $user->getKey())
                ->whereIn('portable_id', $chunk);

            if ($lock) {
                $query->lockForUpdate();
            }

            foreach ($query->get() as $attachment) {
                $existing[$attachment->portable_id] = $attachment;
            }
        }

        return $existing;
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $record
     */
    private function appendManifestRecord($stream, array $record, bool &$first, int &$manifestBytes): void
    {
        $encoded = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $manifestBytes += strlen($encoded) + ($first ? 0 : 1);

        if ($manifestBytes > config('import_export.max_manifest_bytes')) {
            throw new NoteBackupException('The backup manifest exceeds the supported size limit.', 413, 'size_exceeded');
        }

        if (! $first) {
            $this->writeAll($stream, ',');
        }

        $this->writeAll($stream, $encoded);
        $first = false;
    }

    private function ensureRecordLimit(int $recordCount): void
    {
        if ($recordCount > config('import_export.max_records')) {
            throw new NoteBackupException('The backup contains too many records.', 413, 'size_exceeded');
        }
    }

    private function formatTimestamp(mixed $timestamp): string
    {
        if ($timestamp === null) {
            throw new NoteBackupException('A record has a missing timestamp.', 400, 'invalid_record');
        }

        return CarbonImmutable::parse($timestamp)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function resolveAttachmentFile(User $user, NoteAttachment $attachment): string
    {
        $relativePath = (string) $attachment->file_path;
        $requiredPrefix = 'note-attachments/'.$user->getKey().'/';

        if (preg_match(
            '/\A'.preg_quote($requiredPrefix, '/').'[^\/\\\\]+\z/',
            $relativePath,
        ) !== 1) {
            throw $this->invalidItem('attachment', $attachment->original_name, 'The attachment is missing or unreadable.', 'unreadable_attachment', 400);
        }

        $disk = Storage::disk('local');
        $root = realpath($disk->path(''));
        $attachmentsRoot = realpath($disk->path('note-attachments'));
        $userDirectory = $attachmentsRoot === false
            ? false
            : realpath($attachmentsRoot.DIRECTORY_SEPARATOR.$user->getKey());
        $expectedUserDirectory = $attachmentsRoot === false
            ? false
            : $attachmentsRoot.DIRECTORY_SEPARATOR.$user->getKey();
        $realPath = realpath($disk->path($relativePath));

        if ((string) $attachment->user_id !== (string) $user->getKey()
            || ! str_starts_with($relativePath, $requiredPrefix)
            || $root === false
            || $attachmentsRoot === false
            || ! str_starts_with($attachmentsRoot, $root.DIRECTORY_SEPARATOR)
            || $userDirectory === false
            || $userDirectory !== $expectedUserDirectory
            || $realPath === false
            || ! str_starts_with($realPath, $root.DIRECTORY_SEPARATOR)
            || ! str_starts_with($realPath, $userDirectory.DIRECTORY_SEPARATOR)
            || ! is_file($realPath)
            || ! is_readable($realPath)) {
            throw $this->invalidItem('attachment', $attachment->original_name, 'The attachment is missing or unreadable.', 'unreadable_attachment', 400);
        }

        return $realPath;
    }

    private function validateAttachmentFile(string $path, string $displayName): void
    {
        if (! $this->hasAllowedAttachmentExtension($displayName)) {
            throw $this->invalidItem('attachment', $displayName, 'The attachment does not pass the app upload policy.', 'invalid_attachment', 400);
        }

        $validator = Validator::make(
            ['attachment' => new File($path)],
            $this->attachmentValidationRules(),
        );

        if ($validator->fails()) {
            throw $this->invalidItem('attachment', $displayName, 'The attachment does not pass the app upload policy.', 'invalid_attachment', 400);
        }
    }

    private function hasAllowedAttachmentExtension(string $displayName): bool
    {
        $extension = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));

        if ($extension === '') {
            return true;
        }

        foreach ($this->attachmentValidationRules()['attachment'] ?? [] as $rule) {
            if (! str_starts_with($rule, 'mimes:')) {
                continue;
            }

            return in_array($extension, explode(',', substr($rule, 6)), true);
        }

        return false;
    }

    private function invalidItem(
        string $entityType,
        string $displayName,
        string $message,
        string $reasonCode,
        int $status = 400,
    ): NoteBackupException {
        return new NoteBackupException($message, $status, $reasonCode, [[
            'entity_type' => $entityType,
            'display_name' => $this->displayName($displayName),
            'reason_code' => $reasonCode,
            'reason' => $message,
        ]]);
    }

    private function displayName(string $name): string
    {
        return basename(str_replace('\\', '/', $name));
    }

    private function createWorkspace(): string
    {
        $workspace = storage_path('app/private/note-backup-work/'.Str::uuid());

        FileFacade::ensureDirectoryExists($workspace, 0700, true);

        if (! is_dir($workspace) || ! is_writable($workspace) || ! chmod($workspace, 0700)) {
            throw new NoteBackupException('A private backup workspace could not be created.', 500, 'storage_error');
        }

        return $workspace;
    }

    private function removeWorkspace(string $workspace): void
    {
        if (is_dir($workspace) && ! FileFacade::deleteDirectory($workspace)) {
            Log::warning('A temporary note backup workspace could not be removed.', ['path' => $workspace]);
        }
    }

    /** @param resource $destination */
    private function copyFileToStream(string $path, $destination): void
    {
        $source = fopen($path, 'rb');

        if ($source === false) {
            throw new NoteBackupException('A backup manifest section could not be read.', 500, 'storage_error');
        }

        try {
            while (! feof($source)) {
                $chunk = fread($source, 65536);

                if ($chunk === false) {
                    throw new NoteBackupException('A backup manifest section could not be read.', 500, 'storage_error');
                }

                if ($chunk !== '') {
                    $this->writeAll($destination, $chunk);
                }
            }
        } finally {
            fclose($source);
        }
    }

    /** @param resource $stream */
    private function writeAll($stream, string $contents): void
    {
        $offset = 0;

        while ($offset < strlen($contents)) {
            $written = fwrite($stream, substr($contents, $offset));

            if ($written === false || $written === 0) {
                throw new NoteBackupException('A backup file could not be written.', 500, 'storage_error');
            }

            $offset += $written;
        }
    }
}
