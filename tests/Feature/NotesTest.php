<?php

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
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
