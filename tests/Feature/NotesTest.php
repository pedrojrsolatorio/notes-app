<?php

use App\Models\User;
use Livewire\Livewire;

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
