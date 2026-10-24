<?php

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Point the `local` disk at a healthy container-local temp dir.
 *
 * Must be called first inside each test that touches storage. (The sandbox
 * filesystem mount corrupts newly created directories under the project
 * tree, so the default `storage/framework/testing` fake location is
 * unusable here. On a healthy machine this is equivalent to a plain fake.)
 */
function notesTestTmpDisk(): void
{
    $root = sys_get_temp_dir().'/notes-app-tests/'.uniqid();
    mkdir($root, 0777, true);
    config()->set('filesystems.disks.local.root', $root);
    // Livewire stores test-upload temp files on its own `tmp-for-tests` disk.
    config()->set('filesystems.disks.tmp-for-tests', [
        'driver' => 'local',
        'root' => $root.'/livewire-tmp',
        'throw' => false,
    ]);
}

test('saves note edits with content and folder', function () {
    $user = User::factory()->create();
    $folder = $user->folders()->create(['name' => 'Hobbies']);
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->set('title', 'New title')
        ->set('noteFolderId', $folder->id)
        ->call('saveNote', '<p>New content</p>');

    $this->assertDatabaseHas('notes', [
        'id' => $note->id,
        'title' => 'New title',
        'content' => '<p>New content</p>',
        'folder_id' => $folder->id,
    ]);
});

test('saving a note with no folder stores null folder', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->set('noteFolderId', '') // exactly what the browser sends for <option value="">
        ->call('saveNote', '<p>New content</p>');

    $this->assertDatabaseHas('notes', [
        'id' => $note->id,
        'folder_id' => null,
    ]);
});

test('moves a note to trash and hides it from the list', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Trash me', 'content' => 'old']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->call('deleteNote')
        ->assertDontSee('Trash me');

    $this->assertSoftDeleted('notes', ['id' => $note->id]);
});

test('restores a trashed note', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Gone', 'content' => 'old']);
    $note->delete();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('showTrash')
        ->assertSee('Gone')
        ->call('restoreNote', $note->id);

    expect($note->fresh()->trashed())->toBeFalse();
});

test('permanently deletes a trashed note', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Gone forever', 'content' => 'old']);
    $note->delete();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('showTrash')
        ->assertSee('Gone forever')
        ->call('forceDeleteNote', $note->id);

    $this->assertDatabaseMissing('notes', ['id' => $note->id]);
});

test('cannot restore another users trashed note', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $note = $owner->notes()->create(['title' => 'Gone', 'content' => 'old']);
    $note->delete();

    $this->actingAs($intruder);

    try {
        Livewire::test('notes.index')
            ->call('showTrash')
            ->call('restoreNote', $note->id);

        $this->fail('Restoring another users note should fail.');
    } catch (ModelNotFoundException) {
        // Expected: trashed notes are only visible within the owner's scope.
    }

    expect($note->fresh()->trashed())->toBeTrue();
});

test('filters notes by folder', function () {
    $user = User::factory()->create();
    $folder = $user->folders()->create(['name' => 'Hobbies']);
    $user->notes()->create(['title' => 'Folder note', 'content' => 'old', 'folder_id' => $folder->id]);
    $user->notes()->create(['title' => 'Loose note', 'content' => 'old']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectFolder', $folder->id)
        ->assertSee('Folder note')
        ->assertDontSee('Loose note');
});

test('creates a folder with a normalized name and keeps the open note state', function () {
    $user = User::factory()->create();
    $workFolder = $user->folders()->where('name', 'Work')->firstOrFail();
    $schoolFolder = $user->folders()->where('name', 'School')->firstOrFail();
    $note = $user->notes()->create([
        'title' => 'Work note',
        'content' => '<p>Saved content</p>',
        'folder_id' => $workFolder->id,
    ]);
    $otherUser = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('notes.index')
        ->call('selectFolder', $schoolFolder->id)
        ->call('selectNote', $note->id)
        ->set('title', 'Unsaved title')
        ->set('content', '<p>Unsaved editor content</p>')
        ->call('openCreateFolderModal')
        ->set('folderName', "\u{2003}Café\u{00A0}")
        ->call('saveFolder')
        ->assertHasNoErrors()
        ->assertSet('showFolderModal', false)
        ->assertSet('selectedNoteId', $note->id)
        ->assertSet('noteFolderId', $workFolder->id)
        ->assertSet('title', 'Unsaved title')
        ->assertSet('content', '<p>Unsaved editor content</p>')
        ->assertDispatched('toast-show', function (string $name, array $params) {
            return $params['slots']['text'] === 'Folder created.'
                && $params['dataset']['variant'] === 'success';
        });

    $folder = $user->folders()->where('name', 'Café')->firstOrFail();

    $this->assertDatabaseHas('folders', [
        'id' => $folder->id,
        'name' => 'Café',
        'name_key' => 'café',
    ]);
    $this->assertDatabaseHas('notes', [
        'id' => $note->id,
        'title' => 'Work note',
        'folder_id' => $workFolder->id,
    ]);

    $user->folders()->create(['name' => 'Cafe']);
    $otherUser->folders()->create(['name' => 'Café']);

    expect($component->get('selectedFolderId'))->toBe($folder->id);
});

test('rejects a folder name containing only Unicode whitespace', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('openCreateFolderModal')
        ->set('folderName', "\u{3000}\t")
        ->call('saveFolder')
        ->assertHasErrors(['folderName' => 'required'])
        ->assertSet('folderName', "\u{3000}\t")
        ->assertSet('showFolderModal', true)
        ->assertSee('Enter a folder name.')
        ->assertDispatched('toast-show', function (string $name, array $params) {
            return $params['dataset']['variant'] === 'danger';
        })
        ->assertNotDispatched('toast-show', function (string $name, array $params) {
            return $params['dataset']['variant'] === 'success';
        });

    $this->assertDatabaseCount('folders', 4);
});

test('accepts a folder name at the 255 character limit', function () {
    $user = User::factory()->create();
    $name = str_repeat('é', 255);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('openCreateFolderModal')
        ->set('folderName', $name)
        ->call('saveFolder')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('folders', [
        'user_id' => $user->id,
        'name' => $name,
        'name_key' => $name,
    ]);
});

test('rejects a folder name over the 255 character limit', function () {
    $user = User::factory()->create();
    $name = str_repeat('é', 256);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('openCreateFolderModal')
        ->set('folderName', $name)
        ->call('saveFolder')
        ->assertHasErrors(['folderName' => 'max'])
        ->assertSet('folderName', $name)
        ->assertSet('showFolderModal', true)
        ->assertSee('Folder names cannot exceed 255 characters.');

    $this->assertDatabaseCount('folders', 4);
});

test('rejects a folder name that differs only by capitalization', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('openCreateFolderModal')
        ->set('folderName', 'wOrK')
        ->call('saveFolder')
        ->assertHasErrors('folderName')
        ->assertSet('folderName', 'wOrK')
        ->assertSet('showFolderModal', true)
        ->assertSee('A folder with this name already exists.')
        ->assertNotDispatched('toast-show', function (string $name, array $params) {
            return $params['dataset']['variant'] === 'success';
        });

    $this->assertDatabaseCount('folders', 4);
});

test('shows a database uniqueness conflict inline when a duplicate is inserted concurrently', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test('notes.index')
        ->call('openCreateFolderModal')
        ->set('folderName', 'Reading');

    $insertedConflict = false;

    DB::listen(function (QueryExecuted $query) use (&$insertedConflict, $user): void {
        if (
            $insertedConflict
            || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
            || ! str_contains($query->sql, 'name_key')
        ) {
            return;
        }

        $insertedConflict = true;

        DB::table('folders')->insert([
            'user_id' => $user->id,
            'name' => 'Reading',
            'name_key' => 'reading',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $component
        ->call('saveFolder')
        ->assertHasErrors('folderName')
        ->assertSet('folderName', 'Reading')
        ->assertSet('showFolderModal', true)
        ->assertDispatched('toast-show', function (string $name, array $params) {
            return $params['dataset']['variant'] === 'danger';
        })
        ->assertNotDispatched('toast-show', function (string $name, array $params) {
            return $params['dataset']['variant'] === 'success';
        });

    expect($insertedConflict)->toBeTrue();
    $this->assertDatabaseCount('folders', 5);
});

test('renames a folder without changing its notes or selected folder', function () {
    $user = User::factory()->create();
    $folder = $user->folders()->create(['name' => 'Reading']);
    $note = $user->notes()->create([
        'title' => 'Book list',
        'content' => '<p>Books</p>',
        'folder_id' => $folder->id,
    ]);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectFolder', $folder->id)
        ->call('openRenameFolderModal', $folder->id)
        ->assertSet('folderName', 'Reading')
        ->set('folderName', '  Books  ')
        ->call('saveFolder')
        ->assertHasNoErrors()
        ->assertSet('selectedFolderId', $folder->id)
        ->assertSet('showFolderModal', false)
        ->assertSee('Books');

    $this->assertDatabaseHas('folders', [
        'id' => $folder->id,
        'name' => 'Books',
        'name_key' => 'books',
    ]);
    $this->assertDatabaseHas('notes', [
        'id' => $note->id,
        'folder_id' => $folder->id,
    ]);
});

test('rejects renaming a folder to another folders normalized name', function () {
    $user = User::factory()->create();
    $folder = $user->folders()->create(['name' => 'Reading']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectFolder', $folder->id)
        ->call('openRenameFolderModal', $folder->id)
        ->set('folderName', 'wOrK')
        ->call('saveFolder')
        ->assertHasErrors('folderName')
        ->assertSet('selectedFolderId', $folder->id)
        ->assertSet('folderName', 'wOrK')
        ->assertSet('showFolderModal', true)
        ->assertSee('A folder with this name already exists.')
        ->assertNotDispatched('toast-show', function (string $name, array $params) {
            return $params['dataset']['variant'] === 'success';
        });

    $this->assertDatabaseHas('folders', [
        'id' => $folder->id,
        'name' => 'Reading',
        'name_key' => 'reading',
    ]);
});

test('sorts folders by their normalized name key', function () {
    $user = User::factory()->create();
    $user->folders()->create(['name' => 'zebra']);
    $user->folders()->create(['name' => 'aLPha']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->assertSeeInOrder(['All Notes', 'aLPha', 'Personal', 'Projects', 'School', 'Work', 'zebra']);
});

test('deletes a folder and keeps its notes and attachments in All Notes', function () {
    $user = User::factory()->create();
    $folder = $user->folders()->create(['name' => 'Reading']);
    $note = $user->notes()->create([
        'title' => 'Book list',
        'content' => '<p>Books</p>',
        'folder_id' => $folder->id,
    ]);
    $attachment = $note->attachments()->create([
        'user_id' => $user->id,
        'original_name' => 'reading.pdf',
        'file_path' => 'note-attachments/'.$user->id.'/reading.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
    ]);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectFolder', $folder->id)
        ->call('selectNote', $note->id)
        ->assertSee('Permanently delete this folder? Its notes will remain in All Notes without a folder. This cannot be undone.')
        ->set('title', 'Unsaved title')
        ->call('deleteFolder', $folder->id)
        ->assertSet('selectedFolderId', null)
        ->assertSet('isTrashView', false)
        ->assertSet('selectedNoteId', $note->id)
        ->assertSet('noteFolderId', null);

    $this->assertDatabaseHas('notes', [
        'id' => $note->id,
        'title' => 'Book list',
        'content' => '<p>Books</p>',
        'folder_id' => null,
    ]);
    $this->assertDatabaseHas('note_attachments', ['id' => $attachment->id]);
});

test('cannot rename another users folder', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $folder = $owner->folders()->create(['name' => 'Private']);
    $this->actingAs($intruder);

    try {
        Livewire::test('notes.index')->call('openRenameFolderModal', $folder->id);

        $this->fail('Renaming another users folder should fail.');
    } catch (ModelNotFoundException) {
        expect($folder->fresh()->name)->toBe('Private');
    }
});

test('cannot delete another users folder', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $folder = $owner->folders()->create(['name' => 'Private']);
    $this->actingAs($intruder);

    try {
        Livewire::test('notes.index')->call('deleteFolder', $folder->id);

        $this->fail('Deleting another users folder should fail.');
    } catch (ModelNotFoundException) {
        $this->assertDatabaseHas('folders', ['id' => $folder->id, 'name' => 'Private']);
    }
});

test('backfills folder name keys without changing existing display names', function () {
    $user = User::factory()->create();
    $migration = require database_path('migrations/2026_10_24_152100_add_name_key_to_folders_table.php');

    $migration->down();

    $folderId = DB::table('folders')->insertGetId([
        'user_id' => $user->id,
        'name' => "  Café\u{00A0}",
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    $this->assertDatabaseHas('folders', [
        'id' => $folderId,
        'name' => "  Café\u{00A0}",
        'name_key' => 'café',
    ]);
});

test('stops the folder name migration before schema changes when existing names collide', function () {
    $user = User::factory()->create();
    $migration = require database_path('migrations/2026_10_24_152100_add_name_key_to_folders_table.php');

    $migration->down();

    $firstFolderId = DB::table('folders')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Travel',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $secondFolderId = DB::table('folders')->insertGetId([
        'user_id' => $user->id,
        'name' => ' travel ',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        $migration->up();

        $this->fail('The migration should stop when existing names collide.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('No schema or data changes were made.');
    }

    expect(Schema::hasColumn('folders', 'name_key'))->toBeFalse();
    $this->assertDatabaseHas('folders', ['id' => $firstFolderId, 'name' => 'Travel']);
    $this->assertDatabaseHas('folders', ['id' => $secondFolderId, 'name' => ' travel ']);
});

test('searches notes by title and content', function () {
    $user = User::factory()->create();
    $user->notes()->create(['title' => 'Buy milk', 'content' => 'from the store']);
    $user->notes()->create(['title' => 'Read book', 'content' => 'buy more milk first']);
    $user->notes()->create(['title' => 'Call mom', 'content' => 'on Sunday']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->set('search', 'milk')
        ->assertSee('Buy milk')
        ->assertSee('Read book')
        ->assertDontSee('Call mom');
});

test('sorts notes alphabetically by title', function () {
    $user = User::factory()->create();
    $user->notes()->create(['title' => 'Zebra note', 'content' => 'old']);
    $user->notes()->create(['title' => 'Apple note', 'content' => 'old']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->set('sortBy', 'title_asc')
        ->assertSeeInOrder(['Apple note', 'Zebra note']);
});

test('rejects saving a note into another users folder', function () {
    $user = User::factory()->create();
    $victim = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);
    $victimFolder = $victim->folders()->create(['name' => 'Victim']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->set('noteFolderId', $victimFolder->id)
        ->call('saveNote', '<p>New content</p>')
        ->assertHasErrors(['noteFolderId']);

    $this->assertDatabaseHas('notes', [
        'id' => $note->id,
        'folder_id' => null,
    ]);
});

test('rejects an overlong title when saving', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->set('title', str_repeat('a', 256))
        ->call('saveNote', '<p>New content</p>')
        ->assertHasErrors(['title']);

    $this->assertDatabaseHas('notes', [
        'id' => $note->id,
        'title' => 'Old',
    ]);
});

test('rejects an empty title when saving', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->set('title', '')
        ->call('saveNote', '<p>New content</p>')
        ->assertHasErrors(['title']);

    $this->assertDatabaseHas('notes', [
        'id' => $note->id,
        'title' => 'Old',
    ]);
});

test('uploads a valid file to the selected note', function () {
    notesTestTmpDisk();

    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $this->actingAs($user);

    $file = UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 fake content');

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->set('attachment', $file)
        ->call('uploadAttachment')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('note_attachments', [
        'note_id' => $note->id,
        'user_id' => $user->id,
        'original_name' => 'doc.pdf',
    ]);

    $path = $note->attachments()->firstOrFail()->file_path;

    Storage::disk('local')->assertExists($path);
});

test('rejects an upload with a disallowed file type', function () {
    notesTestTmpDisk();

    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $this->actingAs($user);

    $file = UploadedFile::fake()->createWithContent('evil.exe', 'not an allowed type');

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->set('attachment', $file)
        ->call('uploadAttachment')
        ->assertHasErrors(['attachment']);

    $this->assertDatabaseCount('note_attachments', 0);
});

test('rejects an upload exceeding the size limit', function () {
    notesTestTmpDisk();

    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $this->actingAs($user);

    $file = UploadedFile::fake()->create('big.pdf', 11 * 1024);

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->set('attachment', $file)
        ->call('uploadAttachment')
        ->assertHasErrors(['attachment']);

    $this->assertDatabaseCount('note_attachments', 0);
});

test('downloads an attachment owned by the user', function () {
    notesTestTmpDisk();

    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $path = 'note-attachments/'.$user->id.'/doc.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 fake content');

    $attachment = $note->attachments()->create([
        'user_id' => $user->id,
        'original_name' => 'doc.pdf',
        'file_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => 24,
    ]);

    $this->actingAs($user);

    $this->get(route('note-attachments.download', $attachment))
        ->assertOk()
        ->assertDownload('doc.pdf');
});

test('forbids downloading another users attachment', function () {
    notesTestTmpDisk();

    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $note = $owner->notes()->create(['title' => 'Old', 'content' => 'old']);

    $path = 'note-attachments/'.$owner->id.'/doc.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 fake content');

    $attachment = $note->attachments()->create([
        'user_id' => $owner->id,
        'original_name' => 'doc.pdf',
        'file_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => 24,
    ]);

    $this->actingAs($intruder);

    $this->get(route('note-attachments.download', $attachment))
        ->assertForbidden();
});

test('returns 404 when downloading a missing file', function () {
    notesTestTmpDisk();

    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $attachment = $note->attachments()->create([
        'user_id' => $user->id,
        'original_name' => 'doc.pdf',
        'file_path' => 'note-attachments/'.$user->id.'/gone.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 24,
    ]);

    $this->actingAs($user);

    $this->get(route('note-attachments.download', $attachment))
        ->assertNotFound();
});

test('deletes an attachment and its file', function () {
    notesTestTmpDisk();

    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $path = 'note-attachments/'.$user->id.'/doc.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 fake content');

    $attachment = $note->attachments()->create([
        'user_id' => $user->id,
        'original_name' => 'doc.pdf',
        'file_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => 24,
    ]);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->call('deleteAttachment', $attachment->id);

    $this->assertDatabaseMissing('note_attachments', ['id' => $attachment->id]);
    Storage::disk('local')->assertMissing($path);
});

test('cannot delete another users attachment', function () {
    notesTestTmpDisk();

    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $ownerNote = $owner->notes()->create(['title' => 'Old', 'content' => 'old']);
    $intruderNote = $intruder->notes()->create(['title' => 'Mine', 'content' => 'mine']);

    $path = 'note-attachments/'.$owner->id.'/doc.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 fake content');

    $attachment = $ownerNote->attachments()->create([
        'user_id' => $owner->id,
        'original_name' => 'doc.pdf',
        'file_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => 24,
    ]);

    $this->actingAs($intruder);

    try {
        Livewire::test('notes.index')
            ->call('selectNote', $intruderNote->id)
            ->call('deleteAttachment', $attachment->id);

        $this->fail('Deleting another users attachment should fail.');
    } catch (ModelNotFoundException) {
        // Expected: the attachment is invisible outside the owner's notes.
    }

    $this->assertDatabaseHas('note_attachments', ['id' => $attachment->id]);
    Storage::disk('local')->assertExists($path);
});

test('deleting an attachment with a missing file still removes the record', function () {
    notesTestTmpDisk();

    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $attachment = $note->attachments()->create([
        'user_id' => $user->id,
        'original_name' => 'doc.pdf',
        'file_path' => 'note-attachments/'.$user->id.'/gone.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 24,
    ]);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->call('deleteAttachment', $attachment->id);

    $this->assertDatabaseMissing('note_attachments', ['id' => $attachment->id]);
});

test('shows a success toast when saving a note', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->set('title', 'Updated')
        ->call('saveNote', '<p>Body</p>')
        ->assertDispatched('toast-show', function (string $name, array $params) {
            return $params['slots']['text'] === 'Note saved.'
                && $params['dataset']['variant'] === 'success';
        });
});

test('shows an error toast and keeps the note unchanged when saving fails validation', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Original', 'content' => 'original']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->set('title', '')
        ->call('saveNote', '<p>Should not persist</p>')
        ->assertHasErrors('title')
        ->assertDispatched('toast-show', function (string $name, array $params) {
            return $params['dataset']['variant'] === 'danger';
        });

    $this->assertDatabaseHas('notes', [
        'id' => $note->id,
        'title' => 'Original',
        'content' => 'original',
    ]);
});

test('shows a success toast when creating a note', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('createNote')
        ->assertDispatched('toast-show', function (string $name, array $params) {
            return $params['slots']['text'] === 'Note created.'
                && $params['dataset']['variant'] === 'success';
        });
});

test('shows a success toast when uploading an attachment', function () {
    notesTestTmpDisk();

    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $this->actingAs($user);

    $file = UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 fake content');

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->set('attachment', $file)
        ->call('uploadAttachment')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', function (string $name, array $params) {
            return $params['slots']['text'] === 'Attachment uploaded.'
                && $params['dataset']['variant'] === 'success';
        });
});

test('shows an error toast when an attachment upload is rejected', function () {
    notesTestTmpDisk();

    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $this->actingAs($user);

    $file = UploadedFile::fake()->create('malware.exe', 10);

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->set('attachment', $file)
        ->call('uploadAttachment')
        ->assertHasErrors('attachment')
        ->assertDispatched('toast-show', function (string $name, array $params) {
            return $params['dataset']['variant'] === 'danger';
        });

    $this->assertDatabaseCount('note_attachments', 0);
});

test('shows a success toast when deleting and restoring a note', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Old', 'content' => 'old']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->call('deleteNote')
        ->assertDispatched('toast-show', function (string $name, array $params) {
            return $params['slots']['text'] === 'Note moved to trash.';
        });

    Livewire::test('notes.index')
        ->call('restoreNote', $note->id)
        ->assertDispatched('toast-show', function (string $name, array $params) {
            return $params['slots']['text'] === 'Note restored.';
        });

    $this->assertDatabaseHas('notes', ['id' => $note->id, 'deleted_at' => null]);
});
