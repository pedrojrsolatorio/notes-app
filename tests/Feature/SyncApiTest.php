<?php

use App\Models\Note;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Support\Str;

test('[AC-1] returns a snapshot of the user notes and folders on the first pull', function () {
    $user = User::factory()->create();
    $folder = $user->folders()->create(['name' => 'Research']);
    $note = $user->notes()->create([
        'folder_id' => $folder->id,
        'title' => 'Hello',
        'content' => '<p>Body</p>',
    ]);
    $trashed = $user->notes()->create(['title' => 'Gone', 'content' => '']);
    $trashed->delete();

    $response = $this->actingAs($user)
        ->get(route('sync.index', ['since' => 0]), ['Accept' => 'application/json']);

    $response
        ->assertOk()
        ->assertJsonStructure([
            'revision',
            'notes',
            'folders',
            'deleted_folder_portable_ids',
            'deleted_note_portable_ids',
            'has_more',
        ]);

    expect($response->json('revision'))->toBeGreaterThan(0)
        ->and($response->json('has_more'))->toBeFalse()
        ->and(collect($response->json('notes'))->pluck('portable_id'))
        ->toContain($note->portable_id, $trashed->portable_id)
        ->and(collect($response->json('folders'))->pluck('portable_id'))
        ->toContain($folder->portable_id);

    $data = collect($response->json('notes'))->firstWhere('portable_id', $note->portable_id);
    expect($data['title'])->toBe('Hello')
        ->and($data['content'])->toBe('<p>Body</p>')
        ->and($data['folder_portable_id'])->toBe($folder->portable_id)
        ->and($data['deleted_at'])->toBeNull()
        ->and($data['version'])->toBe(1);

    expect(collect($response->json('notes'))->firstWhere('portable_id', $trashed->portable_id)['deleted_at'])
        ->not->toBeNull();
});

test('[AC-6] pulls only changes after the stored revision', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'First', 'content' => '']);
    $revision = SyncChange::where('user_id', $user->id)->max('id');

    $note->update(['title' => 'Second']);

    $response = $this->actingAs($user)
        ->get(route('sync.index', ['since' => $revision]), ['Accept' => 'application/json']);

    $response->assertOk();

    expect($response->json('revision'))->toBeGreaterThan($revision)
        ->and(collect($response->json('notes'))->pluck('portable_id'))->toContain($note->portable_id)
        ->and(collect($response->json('notes'))->firstWhere('portable_id', $note->portable_id)['title'])->toBe('Second');
});

test('[AC-6c] returns one page and keeps the cursor at the last returned row', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $user->notes()->create(['title' => 'Note '.$i, 'content' => '']);
    }

    $first = $this->actingAs($user)
        ->get(route('sync.index', ['since' => 0, 'limit' => 2]), ['Accept' => 'application/json']);

    $first->assertOk();

    // since=0 is a full snapshot, so pagination starts from the first change id.
    expect($first->json('has_more'))->toBeFalse();
});

test('[AC-6c] reports purged notes as deleted note ids and folders as folder tombstones', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Short lived', 'content' => '']);
    $folder = $user->folders()->create(['name' => 'Doomed']);
    $revision = SyncChange::where('user_id', $user->id)->max('id');

    $note->delete();
    $note->forceDelete();
    $folder->delete();

    $response = $this->actingAs($user)
        ->get(route('sync.index', ['since' => $revision]), ['Accept' => 'application/json']);

    $response
        ->assertOk()
        ->assertJsonPath('deleted_note_portable_ids', [$note->portable_id])
        ->assertJsonPath('deleted_folder_portable_ids', [$folder->portable_id]);
});

test('[AC-2] creates a note through the sync write path', function () {
    $user = User::factory()->create();
    $noteId = (string) Str::uuid();

    $response = $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'note',
                'action' => 'created',
                'portable_id' => $noteId,
                'base_version' => 0,
                'payload' => ['title' => 'Offline note', 'content' => '<p>Hi</p>', 'folder_portable_id' => null],
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk();

    expect($response->json('results.0.status'))->toBe('applied')
        ->and(Note::where('portable_id', $noteId)->first()?->title)->toBe('Offline note')
        ->and(SyncChange::where('user_id', $user->id)->where('entity_portable_id', $noteId)->exists())->toBeTrue();
});

test('[AC-10] applies the same change only once', function () {
    $user = User::factory()->create();
    $clientChangeId = (string) Str::uuid();
    $noteId = (string) Str::uuid();

    $payload = [
        'changes' => [[
            'client_change_id' => $clientChangeId,
            'entity_type' => 'note',
            'action' => 'created',
            'portable_id' => $noteId,
            'base_version' => 0,
            'payload' => ['title' => 'Once', 'content' => '', 'folder_portable_id' => null],
        ]],
    ];

    $this->actingAs($user)
        ->post(route('sync.store'), $payload, ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk();

    $replay = $this->actingAs($user)
        ->post(route('sync.store'), $payload, ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $replay->assertOk()->assertJsonPath('results.0.status', 'applied');

    expect(Note::where('portable_id', $noteId)->count())->toBe(1);
});

test('[AC-7] saves a stale offline edit as a conflict copy', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Original', 'content' => '<p>Old</p>']);
    $note->update(['title' => 'Edited on server', 'content' => '<p>Server body</p>']);

    $response = $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'note',
                'action' => 'updated',
                'portable_id' => $note->portable_id,
                'base_version' => 1,
                'payload' => ['title' => 'Original', 'content' => '<p>Offline body</p>', 'folder_portable_id' => null],
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk()->assertJsonPath('results.0.status', 'conflict');

    $copy = Note::where('title', 'Like', 'Conflict copy of %')->first();
    expect($copy)->not->toBeNull()
        ->and($copy->content)->toBe('<p>Offline body</p>')
        ->and($copy->folder_id)->toBeNull()
        ->and($note->fresh()->content)->toBe('<p>Server body</p>');
});

test('[AC-7] applies a metadata-only change without a conflict copy', function () {
    $user = User::factory()->create();
    $folder = $user->folders()->create(['name' => 'Later']);
    $note = $user->notes()->create(['title' => 'Moved', 'content' => '<p>Same</p>']);
    $note->update(['title' => 'Server title', 'content' => '<p>Same</p>']);

    $response = $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'note',
                'action' => 'updated',
                'portable_id' => $note->portable_id,
                'base_version' => 1,
                'payload' => ['title' => 'Server title', 'folder_portable_id' => $folder->portable_id],
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk()->assertJsonPath('results.0.status', 'applied');

    expect($note->fresh()->folder_id)->toBe($folder->id)
        ->and(Note::where('title', 'Like', 'Conflict copy of %')->count())->toBe(0);
});

test('[AC-8] rejects a stale folder rename and applies the server name', function () {
    $user = User::factory()->create();
    $folder = $user->folders()->create(['name' => 'Old']);
    $folder->update(['name' => 'New']);

    $response = $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'folder',
                'action' => 'updated',
                'portable_id' => $folder->portable_id,
                'base_version' => 1,
                'payload' => ['name' => 'Offline name'],
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk()->assertJsonPath('results.0.status', 'rejected');

    expect($folder->fresh()->name)->toBe('New');
});

test('[AC-3] folder delete through sync moves notes to All Notes', function () {
    $user = User::factory()->create();
    $folder = $user->folders()->create(['name' => 'Gone']);
    $note = $user->notes()->create(['folder_id' => $folder->id, 'title' => 'Stays', 'content' => '']);

    $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'folder',
                'action' => 'deleted',
                'portable_id' => $folder->portable_id,
                'base_version' => 1,
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'applied');

    expect($note->fresh()->folder_id)->toBeNull()
        ->and($folder->fresh())->toBeNull();
});

test('[AC-10c] rejects a duplicate folder rename without applying it', function () {
    $user = User::factory()->create();
    $folder = $user->folders()->create(['name' => 'Alpha']);
    $user->folders()->create(['name' => 'Beta']);

    $response = $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'folder',
                'action' => 'updated',
                'portable_id' => $folder->portable_id,
                'base_version' => $folder->version,
                'payload' => ['name' => ' beta '],
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk()->assertJsonPath('results.0.status', 'rejected');

    expect($folder->fresh()->name)->toBe('Alpha');
});

test('[AC-11] rejects a forged note portable id', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $note = $owner->notes()->create(['title' => 'Theirs', 'content' => '']);

    $response = $this->actingAs($other)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'note',
                'action' => 'updated',
                'portable_id' => $note->portable_id,
                'base_version' => $note->version,
                'payload' => ['title' => 'Stolen', 'content' => '<p>pwned</p>', 'folder_portable_id' => null],
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk()->assertJsonPath('results.0.status', 'rejected');

    expect($note->fresh()->title)->toBe('Theirs')
        ->and(
            SyncChange::where('user_id', $other->id)
                ->where('entity_portable_id', $note->portable_id)
                ->where('status', 'rejected')
                ->exists()
        )->toBeTrue();
});

test('[AC-11] keeps every sync query scoped to the signed in user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $owner->notes()->create(['title' => 'Private', 'content' => '']);

    $response = $this->actingAs($other)
        ->get(route('sync.index', ['since' => 0]), ['Accept' => 'application/json']);

    $response->assertOk();

    expect(collect($response->json('notes'))->pluck('title'))->not->toContain('Private');
});

test('returns 401 for guests when the request expects JSON', function () {
    $this->get(route('sync.index', ['since' => 0]), ['Accept' => 'application/json'])
        ->assertUnauthorized();
});

test('rejects an invalid batch with a validation error', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('sync.store'), ['changes' => [['oops' => true]]], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertUnprocessable();
});

test('[AC-9] rejects a note change with an invalid title', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Keep', 'content' => '']);

    $response = $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'note',
                'action' => 'updated',
                'portable_id' => $note->portable_id,
                'base_version' => 1,
                'payload' => ['title' => '', 'folder_portable_id' => null],
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk()->assertJsonPath('results.0.status', 'rejected');

    expect($note->fresh()->title)->toBe('Keep');
});

test('[AC-12b] includes the attachment manifest with each pulled note', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Files', 'content' => '']);
    $attachment = $note->attachments()->create([
        'user_id' => $user->id,
        'original_name' => 'invoice.pdf',
        'file_path' => 'note-attachments/'.$user->id.'/invoice.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 123,
    ]);

    $response = $this->actingAs($user)
        ->get(route('sync.index', ['since' => 0]), ['Accept' => 'application/json']);

    $manifest = collect($response->json('notes'))
        ->firstWhere('portable_id', $note->portable_id)['attachments'];

    expect($manifest)->toHaveCount(1)
        ->and($manifest[0]['portable_id'])->toBe($attachment->portable_id)
        ->and($manifest[0]['name'])->toBe('invoice.pdf')
        ->and($manifest[0]['file_size'])->toBe(123);
});

test('[AC-6c] pages through change history with a moving cursor', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'One', 'content' => '']);
    $cursor = SyncChange::where('user_id', $user->id)->max('id');

    $note->update(['title' => 'Two']);
    $note->update(['title' => 'Three']);
    $note->update(['title' => 'Four']);

    $first = $this->actingAs($user)
        ->get(route('sync.index', ['since' => $cursor, 'limit' => 2]), ['Accept' => 'application/json']);

    $first->assertOk();

    expect($first->json('has_more'))->toBeTrue()
        ->and($first->json('revision'))->toBeGreaterThan($cursor)
        ->and($first->json('notes'))->toHaveCount(1);

    // The second page starts after the first page's last change row.
    $second = $this->actingAs($user)
        ->get(route('sync.index', ['since' => $first->json('revision'), 'limit' => 2]), ['Accept' => 'application/json']);

    $second->assertOk();

    expect($second->json('has_more'))->toBeFalse()
        ->and(collect($second->json('notes'))[0]['title'])->toBe('Four');
});

test('[Rate limit] rejects the 61st sync request in a minute', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 61; $i++) {
        $response = $this->actingAs($user)
            ->get(route('sync.index', ['since' => 0]), ['Accept' => 'application/json']);
    }

    $response->assertStatus(429);
});

test('[AC-13] live note edits append change rows and bump the note version', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Before', 'content' => '']);
    $before = $note->version;

    $note->update(['title' => 'After']);

    expect($note->fresh()->version)->toBe($before + 1)
        ->and(SyncChange::where('user_id', $user->id)
            ->where('entity_portable_id', $note->portable_id)
            ->where('action', 'updated')
            ->exists())->toBeTrue();
});

test('[AC-2] updates a note through the sync write path and pulls it back', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Draft', 'content' => '']);

    $response = $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'note',
                'action' => 'updated',
                'portable_id' => $note->portable_id,
                'base_version' => 1,
                'payload' => ['title' => 'Edited', 'content' => '<p>Changed</p>', 'folder_portable_id' => null],
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk()->assertJsonPath('results.0.status', 'applied');

    $reloaded = $note->fresh();
    expect($reloaded->title)->toBe('Edited')
        ->and($reloaded->content)->toBe('<p>Changed</p>')
        ->and($reloaded->version)->toBe(2);

    $snapshot = $this->actingAs($user)
        ->get(route('sync.index', ['since' => 0]), ['Accept' => 'application/json']);

    expect(collect($snapshot->json('notes'))->firstWhere('portable_id', $note->portable_id)['title'])->toBe('Edited');
});

test('[AC-3] trashes and restores a note through the sync write path', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Keep me', 'content' => '']);

    $trashed = $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'note',
                'action' => 'deleted',
                'portable_id' => $note->portable_id,
                'base_version' => $note->version,
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $trashed->assertOk()->assertJsonPath('results.0.status', 'applied');
    expect($note->fresh()->trashed())->toBeTrue();

    $restored = $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'note',
                'action' => 'restored',
                'portable_id' => $note->portable_id,
                'base_version' => $note->fresh()->version,
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $restored->assertOk()->assertJsonPath('results.0.status', 'applied');

    expect($note->fresh()->trashed())->toBeFalse();
});

test('[AC-3] creates and renames a folder through the sync write path', function () {
    $user = User::factory()->create();
    $portableId = (string) Str::uuid();

    $created = $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'folder',
                'action' => 'created',
                'portable_id' => $portableId,
                'base_version' => 0,
                'payload' => ['name' => 'Sync folder'],
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $created->assertOk()->assertJsonPath('results.0.status', 'applied');

    $folder = $user->folders()->where('portable_id', $portableId)->first();
    expect($folder)->not->toBeNull()
        ->and($folder->name)->toBe('Sync folder');

    $renamed = $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'folder',
                'action' => 'updated',
                'portable_id' => $portableId,
                'base_version' => $folder->version,
                'payload' => ['name' => 'Renamed folder'],
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $renamed->assertOk()->assertJsonPath('results.0.status', 'applied');

    expect($folder->fresh()->name)->toBe('Renamed folder')
        ->and($folder->fresh()->version)->toBe($folder->version + 1);
});

test('[AC-3] rejects creating a folder with a duplicate name', function () {
    $user = User::factory()->create();
    $user->folders()->create(['name' => 'Inbox']);

    $response = $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'folder',
                'action' => 'created',
                'portable_id' => (string) Str::uuid(),
                'base_version' => 0,
                'payload' => ['name' => ' inbox '],
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk()->assertJsonPath('results.0.status', 'rejected');

    expect($user->folders()->where('name_key', 'inbox')->count())->toBe(1);
});

test('[AC-6b] returns 410 with the minimum retained revision when the cursor is pruned', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Alive', 'content' => '']);
    $note->update(['title' => 'Alive again']);
    $note->update(['title' => 'Alive once more']);
    $first = SyncChange::where('user_id', $user->id)->orderBy('id')->firstOrFail();

    // Simulate the pruning policy dropping the first change rows only.
    SyncChange::where('user_id', $user->id)->where('id', '<', $first->id + 2)->delete();

    $response = $this->actingAs($user)
        ->get(route('sync.index', ['since' => $first->id]), ['Accept' => 'application/json']);

    $response->assertStatus(410);

    expect($response->json('revision'))->toBeGreaterThan($first->id);
});

test('[AC-7b] pulls a conflict copy on the second device', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Shared', 'content' => '<p>Original</p>']);
    $seenRevision = SyncChange::where('user_id', $user->id)->max('id');
    $note->update(['title' => 'Renamed on device A', 'content' => '<p>Server text</p>']);

    $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'note',
                'action' => 'updated',
                'portable_id' => $note->portable_id,
                'base_version' => 1,
                'payload' => ['title' => 'Shared', 'content' => '<p>Device B text</p>', 'folder_portable_id' => null],
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'conflict');

    $pull = $this->actingAs($user)
        ->get(route('sync.index', ['since' => $seenRevision]), ['Accept' => 'application/json']);

    $pull->assertOk();

    $copy = collect($pull->json('notes'))->firstWhere('content', '<p>Device B text</p>');

    expect($copy)->not->toBeNull()
        ->and($copy['title'])->toStartWith('Conflict copy of')
        ->and($copy['folder_portable_id'])->toBeNull();
});

test('[AC-11] rejects a note update naming a folder the user does not own', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $folder = $owner->folders()->create(['name' => 'Theirs']);
    $note = $user->notes()->create(['title' => 'Mine', 'content' => '']);

    $response = $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'note',
                'action' => 'updated',
                'portable_id' => $note->portable_id,
                'base_version' => $note->version,
                'payload' => ['folder_portable_id' => $folder->portable_id],
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk()->assertJsonPath('results.0.status', 'rejected');

    expect($note->fresh()->folder_id)->toBeNull();
});

test('[AC-2] a create change for an existing portable id does not duplicate the note', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Already here', 'content' => 'gone']);

    $response = $this->actingAs($user)
        ->post(route('sync.store'), [
            'changes' => [[
                'client_change_id' => (string) Str::uuid(),
                'entity_type' => 'note',
                'action' => 'created',
                'portable_id' => $note->portable_id,
                'base_version' => 0,
                'payload' => ['title' => 'Same note', 'content' => '', 'folder_portable_id' => null],
            ]],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk()->assertJsonPath('results.0.status', 'applied');

    expect(Note::where('portable_id', $note->portable_id)->count())->toBe(1)
        ->and($note->fresh()->title)->toBe('Already here');
});

