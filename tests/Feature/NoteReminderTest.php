<?php

use App\Console\Commands\DispatchDueNoteReminders;
use App\Jobs\SendNoteReminder;
use App\Mail\NoteReminderMail;
use App\Models\User;
use App\Services\NoteBackups\NoteBackupService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('[AC-6, AC-11] opens only the signed in owners active note from its portable id', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $note = $owner->notes()->create([
        'title' => 'Private reminder note',
        'content' => '<p>Private note body</p>',
    ]);

    $this->actingAs($owner)
        ->get(route('notes.index', ['note' => $note->portable_id]))
        ->assertOk()
        ->assertSee($note->title);

    $this->actingAs($otherUser)
        ->get(route('notes.index', ['note' => $note->portable_id]))
        ->assertOk()
        ->assertSee('This note is no longer available')
        ->assertDontSee($note->title)
        ->assertDontSee('Private note body');
});

test('[AC-6] does not reveal a trashed or deleted note through its old link', function () {
    $user = User::factory()->create();
    $trashedNote = $user->notes()->create([
        'title' => 'Trashed private title',
        'content' => '<p>Trashed private body</p>',
    ]);
    $trashedPortableId = $trashedNote->portable_id;
    $trashedNote->delete();

    $deletedNote = $user->notes()->create([
        'title' => 'Deleted private title',
        'content' => '<p>Deleted private body</p>',
    ]);
    $deletedPortableId = $deletedNote->portable_id;
    $deletedNote->forceDelete();

    foreach ([
        [$trashedPortableId, 'Trashed private title', 'Trashed private body'],
        [$deletedPortableId, 'Deleted private title', 'Deleted private body'],
    ] as [$portableId, $title, $body]) {
        $this->actingAs($user)
            ->get(route('notes.index', ['note' => $portableId]))
            ->assertOk()
            ->assertSee('This note is no longer available')
            ->assertDontSee($title)
            ->assertDontSee($body);
    }
});

test('[AC-6, AC-11] returns a guest to the requested note after sign in', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);
    $url = route('notes.index', ['note' => $note->portable_id]);

    $this->get($url)
        ->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', $url);
});

test('[AC-1, AC-2, AC-9, AC-13] saves a future reminder without changing note timestamps or sync records', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);
    $originalUpdatedAt = $note->updated_at->toDateTimeString();
    $originalVersion = $note->version;
    $syncChangeCount = DB::table('sync_changes')->count();

    Livewire::actingAs($user)
        ->test('notes.index')
        ->call('saveReminder', $note->id, '2099-01-01T10:30:00.000Z')
        ->assertHasNoErrors();

    $note->refresh();

    expect($note->reminder_at->utc()->format('Y-m-d H:i:s'))->toBe('2099-01-01 10:30:00')
        ->and($note->reminder_status)->toBe('scheduled')
        ->and(Str::isUuid($note->reminder_generation))->toBeTrue()
        ->and($note->updated_at->toDateTimeString())->toBe($originalUpdatedAt)
        ->and($note->version)->toBe($originalVersion)
        ->and(DB::table('sync_changes')->count())->toBe($syncChangeCount);
});

test('[AC-8] keeps the existing retry cycle when the same reminder instant is saved', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);
    $generation = (string) Str::uuid();

    DB::table('notes')->where('id', $note->id)->update([
        'reminder_at' => '2099-01-01 10:30:00',
        'reminder_status' => 'failed',
        'reminder_generation' => $generation,
    ]);

    Livewire::actingAs($user)
        ->test('notes.index')
        ->call('saveReminder', $note->id, '2099-01-01T10:30:00.000Z')
        ->assertHasNoErrors();

    expect($note->fresh()->reminder_status)->toBe('failed')
        ->and($note->fresh()->reminder_generation)->toBe($generation);
});

test('[AC-2] rejects a past reminder without changing the note', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);

    Livewire::actingAs($user)
        ->test('notes.index')
        ->call('openReminderModal', $note->id)
        ->call('saveReminder', $note->id, '2000-01-01T10:30:00.000Z')
        ->assertHasErrors(['reminderAtUtc'])
        ->assertSee('Choose a time in the future.');

    expect($note->fresh()->reminder_at)->toBeNull()
        ->and($note->fresh()->reminder_status)->toBeNull()
        ->and($note->fresh()->reminder_generation)->toBeNull();
});

test('[AC-1] opens a new reminder with an empty labeled local date and time field', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);

    Livewire::actingAs($user)
        ->test('notes.index')
        ->call('openReminderModal', $note->id)
        ->assertSet('reminderAtUtc', null)
        ->assertSee('Date and time')
        ->assertSee('datetime-local')
        ->assertSee('for="reminder-at"', false)
        ->assertSee('aria-describedby="reminder-server-error reminder-local-error"', false);
});

test('[AC-2] rejects a timestamp that is not in the required UTC minute format', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);

    Livewire::actingAs($user)
        ->test('notes.index')
        ->call('openReminderModal', $note->id)
        ->call('saveReminder', $note->id, '2099-01-01T10:30:00Z')
        ->assertHasErrors(['reminderAtUtc'])
        ->assertSee('Choose a valid date and time.');

    expect($note->fresh()->reminder_at)->toBeNull();
});

test('[AC-11] does not let another user change a note reminder', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $note = $owner->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);

    expect(
        fn () => Livewire::actingAs($otherUser)
            ->test('notes.index')
            ->call('saveReminder', $note->id, '2099-01-01T10:30:00.000Z'),
    )->toThrow(ModelNotFoundException::class);

    expect($note->fresh()->reminder_at)->toBeNull();
});

test('[AC-4, AC-10] queues due reminders and clears reminders that expire in Trash', function () {
    Queue::fake([SendNoteReminder::class]);

    $user = User::factory()->create();
    $activeNote = $user->notes()->create([
        'title' => 'Active reminder',
        'content' => '<p>Content</p>',
    ]);
    $trashedNote = $user->notes()->create([
        'title' => 'Reminder in Trash',
        'content' => '<p>Content</p>',
    ]);
    $activeGeneration = (string) Str::uuid();
    $trashedGeneration = (string) Str::uuid();

    DB::table('notes')->whereIn('id', [$activeNote->id, $trashedNote->id])->update([
        'reminder_at' => '2000-01-01 10:30:00',
        'reminder_status' => 'scheduled',
    ]);
    DB::table('notes')->where('id', $activeNote->id)->update([
        'reminder_generation' => $activeGeneration,
    ]);
    DB::table('notes')->where('id', $trashedNote->id)->update([
        'reminder_generation' => $trashedGeneration,
    ]);
    $trashedNote->delete();

    $this->artisan('app:dispatch-due-note-reminders')->assertSuccessful();

    expect($activeNote->fresh()->reminder_status)->toBe('queued')
        ->and($trashedNote->fresh()->reminder_at)->toBeNull()
        ->and($trashedNote->fresh()->reminder_status)->toBeNull()
        ->and($trashedNote->fresh()->reminder_generation)->toBeNull();

    Queue::assertPushed(
        SendNoteReminder::class,
        fn (SendNoteReminder $job): bool => $job->noteId === $activeNote->id
            && $job->generation === $activeGeneration,
    );
});

test('[AC-4] leaves a due reminder scheduled when queue insertion fails', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);

    DB::table('notes')->where('id', $note->id)->update([
        'reminder_at' => '2000-01-01 10:30:00',
        'reminder_status' => 'scheduled',
        'reminder_generation' => (string) Str::uuid(),
    ]);
    config([
        'queue.default' => 'database',
        'queue.connections.database.connection' => 'missing',
    ]);

    expect(fn () => app(DispatchDueNoteReminders::class)->handle())
        ->toThrow(InvalidArgumentException::class, 'Database connection [missing] not configured.');

    expect($note->fresh()->reminder_status)->toBe('scheduled');
});

test('[AC-5, AC-6, AC-7] sends a generic reminder to the users current verified address and clears it', function () {
    Mail::fake();

    $user = User::factory()->create(['email' => 'current@example.test']);
    $note = $user->notes()->create([
        'title' => 'Private title',
        'content' => '<p>Private note body</p>',
    ]);
    $generation = (string) Str::uuid();
    $url = route('notes.index', ['note' => $note->portable_id]);

    DB::table('notes')->where('id', $note->id)->update([
        'reminder_at' => '2000-01-01 10:30:00',
        'reminder_status' => 'queued',
        'reminder_generation' => $generation,
    ]);
    DB::table('users')->where('id', $user->id)->update([
        'email' => 'updated@example.test',
    ]);

    (new SendNoteReminder($note->id, $generation))->handle();

    Mail::assertSent(
        NoteReminderMail::class,
        fn (NoteReminderMail $mail): bool => $mail->url === $url && $mail->hasTo('updated@example.test'),
    );
    expect($note->fresh()->reminder_at)->toBeNull()
        ->and($note->fresh()->reminder_status)->toBeNull()
        ->and($note->fresh()->reminder_generation)->toBeNull();

    $mail = new NoteReminderMail($url);

    expect($mail->envelope()->subject)->toBe('Your note reminder')
        ->and($mail->render())->toContain('You asked to revisit a note.')
        ->and($mail->render())->toContain('Open your notes to view it.')
        ->and($mail->render())->not->toContain('Private title')
        ->and($mail->render())->not->toContain('Private note body');
});

test('[AC-9] ignores a stale reminder job after the note receives a new generation', function () {
    Mail::fake();

    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);
    $currentGeneration = (string) Str::uuid();

    DB::table('notes')->where('id', $note->id)->update([
        'reminder_at' => '2000-01-01 10:30:00',
        'reminder_status' => 'queued',
        'reminder_generation' => $currentGeneration,
    ]);

    (new SendNoteReminder($note->id, (string) Str::uuid()))->handle();

    Mail::assertNotSent(NoteReminderMail::class);
    expect($note->fresh()->reminder_status)->toBe('queued')
        ->and($note->fresh()->reminder_generation)->toBe($currentGeneration);
});

test('[AC-5] marks a due reminder failed instead of sending to an unverified address', function () {
    Mail::fake();

    $user = User::factory()->unverified()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);
    $generation = (string) Str::uuid();

    DB::table('notes')->where('id', $note->id)->update([
        'reminder_at' => '2000-01-01 10:30:00',
        'reminder_status' => 'queued',
        'reminder_generation' => $generation,
    ]);

    (new SendNoteReminder($note->id, $generation))->handle();

    Mail::assertNotSent(NoteReminderMail::class);
    expect($note->fresh()->reminder_status)->toBe('failed')
        ->and($note->fresh()->reminder_generation)->toBe($generation);
});

test('[AC-8, AC-9] starts a new generation when retrying a failed reminder', function () {
    Queue::fake([SendNoteReminder::class]);

    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);
    $oldGeneration = (string) Str::uuid();

    DB::table('notes')->where('id', $note->id)->update([
        'reminder_at' => '2000-01-01 10:30:00',
        'reminder_status' => 'failed',
        'reminder_generation' => $oldGeneration,
    ]);

    Livewire::actingAs($user)
        ->test('notes.index')
        ->call('retryReminder', $note->id)
        ->assertHasNoErrors();

    $newGeneration = $note->fresh()->reminder_generation;

    expect($note->fresh()->reminder_status)->toBe('queued')
        ->and($newGeneration)->not->toBe($oldGeneration)
        ->and(Str::isUuid($newGeneration))->toBeTrue();

    Queue::assertPushed(
        SendNoteReminder::class,
        fn (SendNoteReminder $job): bool => $job->noteId === $note->id
            && $job->generation === $newGeneration,
    );
});

test('[AC-10] clears a due reminder when a note is restored from Trash', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);

    DB::table('notes')->where('id', $note->id)->update([
        'reminder_at' => '2000-01-01 10:30:00',
        'reminder_status' => 'scheduled',
        'reminder_generation' => (string) Str::uuid(),
    ]);
    $note->delete();

    Livewire::actingAs($user)
        ->test('notes.index')
        ->call('restoreNote', $note->id)
        ->assertHasNoErrors();

    expect($note->fresh()->trashed())->toBeFalse()
        ->and($note->fresh()->reminder_at)->toBeNull()
        ->and($note->fresh()->reminder_status)->toBeNull()
        ->and($note->fresh()->reminder_generation)->toBeNull();
});

test('[AC-10] allows clearing a Trash reminder but rejects scheduling a new one', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);
    $generation = (string) Str::uuid();

    DB::table('notes')->where('id', $note->id)->update([
        'reminder_at' => '2099-01-01 10:30:00',
        'reminder_status' => 'scheduled',
        'reminder_generation' => $generation,
    ]);
    $note->delete();

    Livewire::actingAs($user)
        ->test('notes.index')
        ->call('saveReminder', $note->id, '2099-01-02T10:30:00.000Z')
        ->assertHasErrors(['reminderAtUtc']);

    Livewire::actingAs($user)
        ->test('notes.index')
        ->call('clearReminder', $note->id)
        ->assertHasNoErrors();

    expect($note->fresh()->reminder_at)->toBeNull()
        ->and($note->fresh()->reminder_status)->toBeNull()
        ->and($note->fresh()->reminder_generation)->toBeNull();
});

test('[AC-10] preserves a future reminder when a note is restored from Trash', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);
    $generation = (string) Str::uuid();

    DB::table('notes')->where('id', $note->id)->update([
        'reminder_at' => '2099-01-01 10:30:00',
        'reminder_status' => 'scheduled',
        'reminder_generation' => $generation,
    ]);
    $note->delete();

    Livewire::actingAs($user)
        ->test('notes.index')
        ->call('restoreNote', $note->id)
        ->assertHasNoErrors();

    expect($note->fresh()->trashed())->toBeFalse()
        ->and($note->fresh()->reminder_status)->toBe('scheduled')
        ->and($note->fresh()->reminder_generation)->toBe($generation);
});

test('[AC-12] does not export or restore reminder state in a note backup', function () {
    Storage::fake('local');

    $sourceUser = User::factory()->create();
    $note = $sourceUser->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);

    DB::table('notes')->where('id', $note->id)->update([
        'reminder_at' => '2099-01-01 10:30:00',
        'reminder_status' => 'scheduled',
        'reminder_generation' => (string) Str::uuid(),
    ]);

    $password = 'correct horse battery staple';
    $archivePath = app(NoteBackupService::class)->export($sourceUser, $password);
    $preview = app(NoteBackupService::class)->preview(
        $sourceUser,
        new UploadedFile($archivePath, basename($archivePath), 'application/zip', null, true),
        $password,
    );

    $note->forceDelete();

    app(NoteBackupService::class)->restore(
        $sourceUser,
        $preview['preview_token'],
        $password,
    );

    $restoredNote = $sourceUser->notes()->sole();

    expect($restoredNote->reminder_at)->toBeNull()
        ->and($restoredNote->reminder_status)->toBeNull()
        ->and($restoredNote->reminder_generation)->toBeNull();
});

test('[AC-1] renders actions that match each active reminder state', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'Reminder controls',
        'content' => '<p>Content</p>',
    ]);

    foreach (['scheduled', 'queued', 'failed'] as $status) {
        DB::table('notes')->where('id', $note->id)->update([
            'reminder_at' => '2099-01-01 10:30:00',
            'reminder_status' => $status,
            'reminder_generation' => (string) Str::uuid(),
        ]);

        $page = Livewire::actingAs($user)->test('notes.index');
        $page->assertSee('Change')->assertSee('Clear');

        if ($status === 'failed') {
            $page->assertSee('Retry now');
        } else {
            $page->assertDontSee('Retry now');
        }
    }
});

test('[AC-1, AC-10] shows only a clear action for a reminder in Trash', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note in Trash',
        'content' => '<p>Content</p>',
    ]);

    DB::table('notes')->where('id', $note->id)->update([
        'reminder_at' => '2099-01-01 10:30:00',
        'reminder_status' => 'failed',
        'reminder_generation' => (string) Str::uuid(),
    ]);
    $note->delete();

    Livewire::actingAs($user)
        ->test('notes.index')
        ->set('isTrashView', true)
        ->assertSee('Clear reminder')
        ->assertDontSee('Change')
        ->assertDontSee('Retry now')
        ->assertDontSee('Set reminder');
});

test('[AC-8, AC-9] applies terminal failure only to the matching reminder generation', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);
    $generation = (string) Str::uuid();

    DB::table('notes')->where('id', $note->id)->update([
        'reminder_at' => '2000-01-01 10:30:00',
        'reminder_status' => 'queued',
        'reminder_generation' => $generation,
    ]);

    (new SendNoteReminder($note->id, $generation))->failed(new RuntimeException('Mail delivery failed.'));

    expect($note->fresh()->reminder_status)->toBe('failed')
        ->and($note->fresh()->reminder_generation)->toBe($generation);

    $newGeneration = (string) Str::uuid();
    DB::table('notes')->where('id', $note->id)->update([
        'reminder_status' => 'queued',
        'reminder_generation' => $newGeneration,
    ]);

    (new SendNoteReminder($note->id, $generation))->failed(new RuntimeException('Stale failure.'));

    expect($note->fresh()->reminder_status)->toBe('queued')
        ->and($note->fresh()->reminder_generation)->toBe($newGeneration);
});

test('[AC-10] does not retry a failed reminder while its note is in Trash', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);
    $generation = (string) Str::uuid();

    DB::table('notes')->where('id', $note->id)->update([
        'reminder_at' => '2099-01-01 10:30:00',
        'reminder_status' => 'failed',
        'reminder_generation' => $generation,
    ]);
    $note->delete();

    Livewire::actingAs($user)
        ->test('notes.index')
        ->call('retryReminder', $note->id)
        ->assertHasNoErrors();

    expect($note->fresh()->reminder_status)->toBe('failed')
        ->and($note->fresh()->reminder_generation)->toBe($generation);
});

test('[AC-10] clears a queued reminder without email after its note moves to Trash', function () {
    Mail::fake();

    $user = User::factory()->create();
    $note = $user->notes()->create([
        'title' => 'A note to revisit',
        'content' => '<p>Content</p>',
    ]);
    $generation = (string) Str::uuid();

    DB::table('notes')->where('id', $note->id)->update([
        'reminder_at' => '2000-01-01 10:30:00',
        'reminder_status' => 'queued',
        'reminder_generation' => $generation,
    ]);
    $note->delete();

    (new SendNoteReminder($note->id, $generation))->handle();

    Mail::assertNotSent(NoteReminderMail::class);
    expect($note->fresh()->reminder_at)->toBeNull()
        ->and($note->fresh()->reminder_status)->toBeNull()
        ->and($note->fresh()->reminder_generation)->toBeNull();
});
