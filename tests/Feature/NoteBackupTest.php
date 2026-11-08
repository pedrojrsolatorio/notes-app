<?php

use App\Models\NoteAttachment;
use App\Models\User;
use App\Services\NoteBackups\BackupArchive;
use App\Services\NoteBackups\NoteBackupException;
use App\Services\NoteBackups\NoteBackupPreviewStore;
use App\Services\NoteBackups\NoteBackupService;
use App\Services\NoteBackups\StrictJsonDecoder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $root = sys_get_temp_dir().'/notes-app-backup-tests/'.Str::uuid();
    File::ensureDirectoryExists($root);
    config()->set('filesystems.disks.local.root', $root);
    Storage::forgetDisk('local');
});

afterEach(function (): void {
    $root = config('filesystems.disks.local.root');
    Storage::forgetDisk('local');
    File::deleteDirectory($root);
});

function noteBackupUploadedFile(string $path): UploadedFile
{
    return new UploadedFile($path, 'notes-backup.zip', 'application/zip', null, true);
}

function noteBackupPdfContent(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF\n";
}

/**
 * @param  array<string, mixed>  $manifest
 * @param  array<string, string>  $attachmentContents
 */
function createNoteBackupArchive(array $manifest, array $attachmentContents, string $password): string
{
    $root = config('filesystems.disks.local.root');
    $innerPath = $root.'/inner-'.Str::uuid().'.zip';
    $outerPath = $root.'/outer-'.Str::uuid().'.zip';
    $inner = new ZipArchive;

    expect($inner->open($innerPath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    expect($inner->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)))->toBeTrue();

    foreach ($attachmentContents as $portableId => $contents) {
        expect($inner->addFromString('attachments/'.$portableId, $contents))->toBeTrue();
    }

    expect($inner->close())->toBeTrue();
    app(BackupArchive::class)->createEncryptedArchive($innerPath, $outerPath, $password);

    return $outerPath;
}

test('[AC-5] requires a verified account to open backup settings', function () {
    $this->get(route('import-export.edit'))
        ->assertRedirect(route('login'));

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('import-export.edit'))
        ->assertRedirect(route('verification.notice'));

    $verifiedUser = User::factory()->create();

    $this->actingAs($verifiedUser)
        ->get(route('import-export.edit'))
        ->assertOk()
        ->assertSee('Export a backup')
        ->assertSee('Restore a backup')
        ->assertSee('Download encrypted backup')
        ->assertSee('Review backup')
        ->assertSee('Backup preview')
        ->assertSee('Enter the backup password again');
});

test('[AC-2] requires matching password confirmation to export a backup', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('import-export.export'), [
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'a different password',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $this->actingAs($user)
        ->post(route('import-export.export'), [
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])
        ->assertDownload('notes-backup.zip');
});

test('[AC-1, AC-2, AC-4, AC-6, AC-7, AC-8, AC-9, AC-13, AC-14] exports and restores encrypted notes additively with attachments', function () {
    $source = User::factory()->create();
    $sourceFolder = $source->folders()->create(['name' => 'Research']);
    $duplicateNote = $source->notes()->create([
        'folder_id' => $sourceFolder->id,
        'title' => 'Source duplicate',
        'content' => '<p>Source text</p>',
    ]);
    $newNote = $source->notes()->create([
        'folder_id' => $sourceFolder->id,
        'title' => 'New imported note',
        'content' => '<p>Imported <strong>safe</strong></p><script>window.bad()</script><a href="javascript:alert(1)">unsafe</a><form action="/attack"><input type="text"></form>',
    ]);
    $newNote->delete();

    $attachmentPath = 'note-attachments/'.$source->getKey().'/source.pdf';
    $attachmentContent = noteBackupPdfContent();
    Storage::disk('local')->put($attachmentPath, $attachmentContent);
    $attachment = $duplicateNote->attachments()->create([
        'user_id' => $source->getKey(),
        'original_name' => 'source.pdf',
        'file_path' => $attachmentPath,
        'mime_type' => 'application/pdf',
        'file_size' => strlen($attachmentContent),
    ]);

    $destination = User::factory()->create();
    $destinationFolder = $destination->folders()->create(['name' => ' research ']);
    $existingNote = $destination->notes()->create([
        'folder_id' => $destinationFolder->id,
        'portable_id' => $duplicateNote->portable_id,
        'title' => 'Keep this title',
        'content' => '<p>Keep this content</p>',
    ]);
    $password = 'correct horse battery staple';
    $archivePath = app(NoteBackupService::class)->export($source, $password);

    $outerArchive = new ZipArchive;
    expect($outerArchive->open($archivePath))->toBeTrue();
    $entries = [
        $outerArchive->getNameIndex(0),
        $outerArchive->getNameIndex(1),
    ];
    sort($entries);
    expect($entries)->toBe(['format.json', 'payload.bin']);
    $format = $outerArchive->getFromName('format.json');
    $payload = $outerArchive->getFromName('payload.bin');
    expect($format)->toBeString()->not->toContain('New imported note');
    $formatData = json_decode($format, true, flags: JSON_THROW_ON_ERROR);
    expect($formatData['format_version'])->toBe(1);
    expect($formatData['kdf'])->toBe('argon2id13');
    expect($formatData['opslimit'])->toBe(3);
    expect($formatData['memlimit'])->toBe(67108864);
    expect($formatData['cipher'])->toBe('secretstream_xchacha20poly1305');
    expect(strlen((string) base64_decode($formatData['salt'], true)))->toBe(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    expect($outerArchive->statName('payload.bin')['size'])->toBeGreaterThan(0);
    expect($payload)
        ->toBeString()
        ->not->toContain('New imported note')
        ->not->toContain($attachmentContent)
        ->not->toContain('note-attachments');
    $outerArchive->close();

    $previewResponse = $this->actingAs($destination)->post(
        route('import-export.preview'),
        [
            'archive' => noteBackupUploadedFile($archivePath),
            'password' => $password,
        ],
        ['Accept' => 'application/json'],
    );
    $previewResponse
        ->assertOk()
        ->assertJsonPath('counts.folders.reused', 5)
        ->assertJsonPath('counts.notes.created', 1)
        ->assertJsonPath('counts.notes.skipped', 1)
        ->assertJsonPath('counts.attachments.created', 1);

    $restoreResponse = $this->actingAs($destination)->postJson(route('import-export.import'), [
        'preview_token' => $previewResponse->json('preview_token'),
        'password' => $password,
    ]);
    $restoreResponse
        ->assertOk()
        ->assertJsonPath('counts.folders.reused', 5)
        ->assertJsonPath('counts.notes.created', 1)
        ->assertJsonPath('counts.notes.skipped', 1)
        ->assertJsonPath('counts.attachments.created', 1)
        ->assertJsonPath('skipped_items.0.display_name', 'Source duplicate')
        ->assertJsonPath('skipped_items.0.reason_code', 'note_already_exists');

    expect($existingNote->fresh()->title)->toBe('Keep this title');
    expect($existingNote->fresh()->content)->toBe('<p>Keep this content</p>');

    $importedNote = $destination->notes()
        ->withTrashed()
        ->where('portable_id', $newNote->portable_id)
        ->firstOrFail();
    expect($importedNote->trashed())->toBeTrue();
    expect($importedNote->folder_id)->toBe($destinationFolder->getKey());
    expect($importedNote->content)
        ->toContain('<p>Imported <strong>safe</strong></p>')
        ->toContain('unsafe')
        ->not->toContain('<script>')
        ->not->toContain('javascript:')
        ->not->toContain('<form')
        ->not->toContain('type="text"');

    $importedAttachment = NoteAttachment::query()
        ->where('portable_id', $attachment->portable_id)
        ->where('user_id', $destination->getKey())
        ->firstOrFail();
    expect($importedAttachment->note_id)->toBe($existingNote->getKey());
    expect(Storage::disk('local')->get($importedAttachment->file_path))->toBe($attachmentContent);
});

test('[AC-2, AC-3] returns one authentication error for an incorrect password or damaged payload', function () {
    $user = User::factory()->create();
    $archivePath = app(NoteBackupService::class)->export($user, 'correct horse battery staple');

    $wrongPasswordResponse = $this->actingAs($user)->post(
        route('import-export.preview'),
        [
            'archive' => noteBackupUploadedFile($archivePath),
            'password' => 'incorrect password',
        ],
        ['Accept' => 'application/json'],
    );
    $wrongPasswordResponse
        ->assertUnprocessable()
        ->assertJsonPath('code', 'authentication_failed')
        ->assertJsonPath('message', 'The password is incorrect or the backup is damaged.');

    $sourceArchive = new ZipArchive;
    expect($sourceArchive->open($archivePath))->toBeTrue();
    $format = $sourceArchive->getFromName('format.json');
    $payload = $sourceArchive->getFromName('payload.bin');
    $sourceArchive->close();
    expect($format)->toBeString();
    expect($payload)->toBeString();

    $lastByte = strlen($payload) - 1;
    $payload[$lastByte] = chr(ord($payload[$lastByte]) ^ 1);
    $damagedPath = config('filesystems.disks.local.root').'/damaged.zip';
    $damagedArchive = new ZipArchive;
    expect($damagedArchive->open($damagedPath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    expect($damagedArchive->addFromString('format.json', $format))->toBeTrue();
    expect($damagedArchive->addFromString('payload.bin', $payload))->toBeTrue();
    expect($damagedArchive->setCompressionName('payload.bin', ZipArchive::CM_STORE))->toBeTrue();
    expect($damagedArchive->close())->toBeTrue();

    $damagedResponse = $this->actingAs($user)->post(
        route('import-export.preview'),
        [
            'archive' => noteBackupUploadedFile($damagedPath),
            'password' => 'correct horse battery staple',
        ],
        ['Accept' => 'application/json'],
    );
    $damagedResponse
        ->assertUnprocessable()
        ->assertJsonPath('code', 'authentication_failed')
        ->assertJsonPath('message', $wrongPasswordResponse->json('message'));

    expect(Storage::disk('local')->allFiles('note-backups/previews'))->toBe([]);
});

test('[AC-14] rejects an attachment path that resolves outside its owner folder', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $note = $owner->notes()->create(['title' => 'Private note', 'content' => '']);
    $otherPath = 'note-attachments/'.$otherUser->getKey().'/private.pdf';
    $content = noteBackupPdfContent();
    Storage::disk('local')->put($otherPath, $content);

    $note->attachments()->create([
        'user_id' => $owner->getKey(),
        'original_name' => 'private.pdf',
        'file_path' => 'note-attachments/'.$owner->getKey().'/../../'.$otherPath,
        'mime_type' => 'application/pdf',
        'file_size' => strlen($content),
    ]);

    try {
        app(NoteBackupService::class)->export($owner, 'correct horse battery staple');
        $this->fail('Export should reject an attachment path outside the owner folder.');
    } catch (NoteBackupException $exception) {
        expect($exception->reasonCode)->toBe('unreadable_attachment');
    }
});

test('[AC-12] accepts and restores an empty backup', function () {
    $user = User::factory()->create();
    $user->folders()->delete();
    $password = 'correct horse battery staple';
    $archivePath = app(NoteBackupService::class)->export($user, $password);

    $preview = app(NoteBackupService::class)->preview(
        $user,
        noteBackupUploadedFile($archivePath),
        $password,
    );
    expect($preview['counts'])->toBe([
        'folders' => ['created' => 0, 'reused' => 0, 'skipped' => 0],
        'notes' => ['created' => 0, 'reused' => 0, 'skipped' => 0],
        'attachments' => ['created' => 0, 'reused' => 0, 'skipped' => 0],
    ]);

    $result = app(NoteBackupService::class)->restore($user, $preview['preview_token'], $password);
    expect($result['counts'])->toBe($preview['counts']);
    expect($user->folders()->count())->toBe(0);
    expect($user->notes()->withTrashed()->count())->toBe(0);
});

test('[AC-11] rejects an archive above the configured outer size limit', function () {
    $user = User::factory()->create();
    $archivePath = app(NoteBackupService::class)->export($user, 'correct horse battery staple');
    $archiveSize = filesize($archivePath);
    expect($archiveSize)->toBeInt();
    config()->set('import_export.max_archive_bytes', $archiveSize - 1);

    $this->actingAs($user)->post(
        route('import-export.preview'),
        [
            'archive' => noteBackupUploadedFile($archivePath),
            'password' => 'correct horse battery staple',
        ],
        ['Accept' => 'application/json'],
    )
        ->assertStatus(413)
        ->assertJsonPath('code', 'size_exceeded');

    expect(Storage::disk('local')->allFiles('note-backups/previews'))->toBe([]);
});

test('[AC-3] rejects duplicate JSON object keys at any depth', function () {
    try {
        app(StrictJsonDecoder::class)->decode('{"safe":{"value":1,"value":2}}');
        $this->fail('The decoder should reject repeated object keys.');
    } catch (NoteBackupException $exception) {
        expect($exception->reasonCode)->toBe('duplicate_json_key');
    }
});

test('[AC-3, AC-10] rolls back database records and copied files when an attachment write fails', function () {
    $source = User::factory()->create();
    $note = $source->notes()->create(['title' => 'Imported note', 'content' => '<p>Content</p>']);
    $path = 'note-attachments/'.$source->getKey().'/source.pdf';
    $content = noteBackupPdfContent();
    Storage::disk('local')->put($path, $content);
    $note->attachments()->create([
        'user_id' => $source->getKey(),
        'original_name' => 'source.pdf',
        'file_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => strlen($content),
    ]);

    $destination = User::factory()->create();
    $password = 'correct horse battery staple';
    $archivePath = app(NoteBackupService::class)->export($source, $password);
    $preview = app(NoteBackupService::class)->preview(
        $destination,
        noteBackupUploadedFile($archivePath),
        $password,
    );
    $triggerName = 'fail_note_backup_attachment_insert';
    DB::unprepared("CREATE TRIGGER {$triggerName} BEFORE INSERT ON note_attachments BEGIN SELECT RAISE(ABORT, 'test failure'); END");

    try {
        app(NoteBackupService::class)->restore($destination, $preview['preview_token'], $password);
        $this->fail('The attachment insert should fail and roll back the restore.');
    } catch (QueryException) {
        expect($destination->notes()->withTrashed()->where('portable_id', $note->portable_id)->exists())->toBeFalse();
        expect(Storage::disk('local')->files('note-attachments/'.$destination->getKey()))->toBe([]);
        expect(Storage::disk('local')->allFiles('note-backups/previews'))->toBe([]);
    } finally {
        DB::unprepared("DROP TRIGGER IF EXISTS {$triggerName}");
    }
});

test('[AC-10] shares the five attempt limit across backup actions', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson(route('import-export.export'), [])
        ->assertUnprocessable();
    $this->actingAs($user)->postJson(route('import-export.export'), [])
        ->assertUnprocessable();
    $this->actingAs($user)->postJson(route('import-export.export'), [])
        ->assertUnprocessable();
    $this->actingAs($user)->postJson(route('import-export.preview'), [])
        ->assertUnprocessable();
    $this->actingAs($user)->postJson(route('import-export.import'), [])
        ->assertUnprocessable();

    $throttledResponse = $this->actingAs($user)->postJson(route('import-export.export'), []);
    $throttledResponse
        ->assertStatus(429)
        ->assertHeader('Retry-After');
    expect($throttledResponse->json('message'))
        ->toStartWith('Too many backup attempts.');
});

test('[AC-10] protects and expires staged previews by account', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $upload = UploadedFile::fake()->createWithContent('notes-backup.zip', 'not an archive');
    $token = app(NoteBackupPreviewStore::class)->stage($owner->getKey(), $upload);

    $this->actingAs($otherUser)
        ->postJson(route('import-export.import'), [
            'preview_token' => $token,
            'password' => 'any password',
        ])
        ->assertStatus(400)
        ->assertJsonPath('code', 'preview_expired');

    expect(Storage::disk('local')->allFiles('note-backups/previews'))->toHaveCount(1);

    $this->travel(16)->minutes();

    $this->actingAs($owner)
        ->postJson(route('import-export.import'), [
            'preview_token' => $token,
            'password' => 'any password',
        ])
        ->assertStatus(400)
        ->assertJsonPath('code', 'preview_expired');

    expect(Storage::disk('local')->allFiles('note-backups/previews'))->toBe([]);
});

test('[AC-6] reuses a folder with a matching portable ID without changing its name or timestamps', function () {
    $source = User::factory()->create();
    $sourceFolder = $source->folders()->create(['name' => 'Original folder']);
    $destination = User::factory()->create();
    $destinationFolder = $destination->folders()->create([
        'name' => 'Destination folder',
        'portable_id' => $sourceFolder->portable_id,
    ]);
    $originalUpdatedAt = $destinationFolder->updated_at;
    $password = 'correct horse battery staple';
    $archivePath = app(NoteBackupService::class)->export($source, $password);
    $preview = app(NoteBackupService::class)->preview(
        $destination,
        noteBackupUploadedFile($archivePath),
        $password,
    );

    $result = app(NoteBackupService::class)->restore($destination, $preview['preview_token'], $password);

    expect($result['counts']['folders']['reused'])->toBe(5);
    expect($destinationFolder->fresh()->name)->toBe('Destination folder');
    expect($destinationFolder->fresh()->updated_at->toDateTimeString())->toBe($originalUpdatedAt->toDateTimeString());
    expect($destination->folders()->where('portable_id', $sourceFolder->portable_id)->count())->toBe(1);
});

test('[AC-7] preserves note and attachment timestamps and metadata during restore', function () {
    $source = User::factory()->create();
    $note = $source->notes()->create([
        'title' => 'Timestamped note',
        'content' => '<p>Preserved content</p>',
    ]);
    DB::table('notes')->where('id', $note->getKey())->update([
        'created_at' => '2020-01-02 03:04:05',
        'updated_at' => '2021-02-03 04:05:06',
        'deleted_at' => '2022-03-04 05:06:07',
    ]);

    $path = 'note-attachments/'.$source->getKey().'/timestamped.pdf';
    $contents = noteBackupPdfContent();
    Storage::disk('local')->put($path, $contents);
    $attachment = $note->attachments()->create([
        'user_id' => $source->getKey(),
        'original_name' => 'timestamped.pdf',
        'file_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => strlen($contents),
    ]);
    DB::table('note_attachments')->where('id', $attachment->getKey())->update([
        'created_at' => '2023-04-05 06:07:08',
        'updated_at' => '2023-04-05 06:07:08',
    ]);

    $destination = User::factory()->create();
    $password = 'correct horse battery staple';
    $archivePath = app(NoteBackupService::class)->export($source, $password);
    $preview = app(NoteBackupService::class)->preview(
        $destination,
        noteBackupUploadedFile($archivePath),
        $password,
    );
    app(NoteBackupService::class)->restore($destination, $preview['preview_token'], $password);

    $restoredNote = $destination->notes()
        ->withTrashed()
        ->where('portable_id', $note->portable_id)
        ->firstOrFail();
    $restoredAttachment = NoteAttachment::query()
        ->where('portable_id', $attachment->portable_id)
        ->where('user_id', $destination->getKey())
        ->firstOrFail();

    expect($restoredNote->created_at->utc()->format('Y-m-d\TH:i:s.u\Z'))->toBe('2020-01-02T03:04:05.000000Z');
    expect($restoredNote->updated_at->utc()->format('Y-m-d\TH:i:s.u\Z'))->toBe('2021-02-03T04:05:06.000000Z');
    expect($restoredNote->deleted_at->utc()->format('Y-m-d\TH:i:s.u\Z'))->toBe('2022-03-04T05:06:07.000000Z');
    expect($restoredAttachment->created_at->utc()->format('Y-m-d\TH:i:s.u\Z'))->toBe('2023-04-05T06:07:08.000000Z');
    expect($restoredAttachment->original_name)->toBe('timestamped.pdf');
    expect($restoredAttachment->mime_type)->toBe('application/pdf');
    expect($restoredAttachment->file_size)->toBe(strlen($contents));
});

test('[AC-8] sanitizes the exported copy without changing the source note', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'Unsafe source',
        'content' => '<p>Safe</p><script>window.bad()</script><a href="javascript:alert(1)">unsafe link</a>',
    ]);
    $password = 'correct horse battery staple';
    $archivePath = app(NoteBackupService::class)->export($user, $password);
    $workspace = config('filesystems.disks.local.root').'/inspection-'.Str::uuid();
    File::ensureDirectoryExists($workspace);

    try {
        $innerPath = app(BackupArchive::class)->decryptOuterArchive($archivePath, $password, $workspace);
        $backup = app(BackupArchive::class)->extractInnerArchive($innerPath, $workspace);
    } finally {
        File::deleteDirectory($workspace);
    }

    expect($backup['manifest']['notes'][0]['content'])
        ->toContain('<p>Safe</p>')
        ->toContain('unsafe link')
        ->not->toContain('<script>')
        ->not->toContain('javascript:');
    expect($note->fresh()->content)->toContain('<script>')->toContain('javascript:');
});

test('[AC-9] rejects an unsupported attachment before staging or writing imported records', function () {
    $user = User::factory()->create();
    $password = 'correct horse battery staple';
    $noteId = (string) Str::uuid();
    $attachmentId = (string) Str::uuid();
    $contents = 'not a supported file format';
    $archivePath = createNoteBackupArchive([
        'format_version' => 1,
        'folders' => [],
        'notes' => [[
            'portable_id' => $noteId,
            'folder_portable_id' => null,
            'title' => 'Must not be imported',
            'content' => '<p>Content</p>',
            'deleted_at' => null,
            'created_at' => '2020-01-02T03:04:05.000000Z',
            'updated_at' => '2020-01-02T03:04:05.000000Z',
        ]],
        'attachments' => [[
            'portable_id' => $attachmentId,
            'note_portable_id' => $noteId,
            'original_name' => 'blocked.exe',
            'mime_type' => 'application/octet-stream',
            'file_size' => strlen($contents),
            'created_at' => '2020-01-02T03:04:05.000000Z',
            'payload_path' => 'attachments/'.$attachmentId,
        ]],
    ], [$attachmentId => $contents], $password);

    $this->actingAs($user)->post(
        route('import-export.preview'),
        [
            'archive' => noteBackupUploadedFile($archivePath),
            'password' => $password,
        ],
        ['Accept' => 'application/json'],
    )
        ->assertBadRequest()
        ->assertJsonPath('code', 'invalid_items')
        ->assertJsonPath('items.0.display_name', 'blocked.exe')
        ->assertJsonPath('items.0.reason_code', 'invalid_attachment');

    $this->assertDatabaseMissing('notes', ['portable_id' => $noteId]);
    $this->assertDatabaseMissing('note_attachments', ['portable_id' => $attachmentId]);
    expect(Storage::disk('local')->allFiles('note-backups/previews'))->toBe([]);
});

test('[AC-10] deletes a staged archive immediately when the preview is cancelled', function () {
    $user = User::factory()->create();
    $upload = UploadedFile::fake()->createWithContent('notes-backup.zip', 'staged preview');
    $token = app(NoteBackupPreviewStore::class)->stage($user->getKey(), $upload);

    $this->actingAs($user)
        ->postJson(route('import-export.cancel'), ['preview_token' => $token])
        ->assertOk()
        ->assertJsonPath('message', 'The backup preview was cancelled.');

    expect(Storage::disk('local')->allFiles('note-backups/previews'))->toBe([]);
});

test('[AC-5] returns 401 for unauthenticated backup actions', function () {
    foreach ([
        'import-export.export',
        'import-export.preview',
        'import-export.import',
        'import-export.cancel',
    ] as $routeName) {
        $this->postJson(route($routeName), [])->assertUnauthorized();
    }
});

test('[AC-4, AC-13] skips an existing attachment without replacing it and reports the reason', function () {
    $source = User::factory()->create();
    $sourceNote = $source->notes()->create(['title' => 'Shared note', 'content' => '<p>Source</p>']);
    $sourcePath = 'note-attachments/'.$source->getKey().'/source.pdf';
    $sourceContents = noteBackupPdfContent();
    Storage::disk('local')->put($sourcePath, $sourceContents);
    $sourceAttachment = $sourceNote->attachments()->create([
        'user_id' => $source->getKey(),
        'original_name' => 'source.pdf',
        'file_path' => $sourcePath,
        'mime_type' => 'application/pdf',
        'file_size' => strlen($sourceContents),
    ]);

    $destination = User::factory()->create();
    $destinationNote = $destination->notes()->create([
        'title' => 'Keep this note',
        'content' => '<p>Keep this content</p>',
        'portable_id' => $sourceNote->portable_id,
    ]);
    $destinationPath = 'note-attachments/'.$destination->getKey().'/existing.pdf';
    $destinationContents = "%PDF-1.4\nexisting attachment\n";
    Storage::disk('local')->put($destinationPath, $destinationContents);
    $existingAttachment = $destinationNote->attachments()->create([
        'user_id' => $destination->getKey(),
        'original_name' => 'keep.pdf',
        'file_path' => $destinationPath,
        'mime_type' => 'application/pdf',
        'file_size' => strlen($destinationContents),
        'portable_id' => $sourceAttachment->portable_id,
    ]);

    $password = 'correct horse battery staple';
    $archivePath = app(NoteBackupService::class)->export($source, $password);
    $preview = app(NoteBackupService::class)->preview(
        $destination,
        noteBackupUploadedFile($archivePath),
        $password,
    );

    $result = app(NoteBackupService::class)->restore($destination, $preview['preview_token'], $password);

    expect($result['counts']['attachments']['skipped'])->toBe(1);
    expect($result['skipped_items'])->toContain([
        'entity_type' => 'attachment',
        'display_name' => 'source.pdf',
        'reason_code' => 'portable_id_exists',
    ]);
    expect($existingAttachment->fresh()->original_name)->toBe('keep.pdf');
    expect(Storage::disk('local')->get($destinationPath))->toBe($destinationContents);
    expect($destinationNote->fresh()->content)->toBe('<p>Keep this content</p>');
});
