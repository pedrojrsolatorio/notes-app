<?php

use App\Models\Note;
use App\Models\User;
use Livewire\Livewire;

test('[AC-1] opens quick capture with an empty field', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->set('quickCaptureText', 'stale text')
        ->call('openQuickCapture')
        ->assertSet('showQuickCaptureModal', true)
        ->assertSet('quickCaptureText', '')
        ->assertSee('Quick capture')
        ->assertSee('Capture a thought')
        ->assertSee('Write something to capture.');
});

test('[AC-2] captures the first line as the title and the rest as the body', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('openQuickCapture')
        ->set('quickCaptureText', "Buy milk\n2 litres\nAnd eggs")
        ->call('saveQuickCapture')
        ->assertHasNoErrors()
        ->assertSet('showQuickCaptureModal', false)
        ->assertSet('quickCaptureText', '')
        ->assertDispatched('toast-show', function (string $name, array $params) {
            return $params['slots']['text'] === 'Note captured.'
                && $params['dataset']['variant'] === 'success';
        });

    $this->assertDatabaseHas('notes', [
        'user_id' => $user->id,
        'title' => 'Buy milk',
        'content' => '<p>2 litres</p><p>And eggs</p>',
        'folder_id' => null,
    ]);

    $this->assertDatabaseCount('notes', 1);

    $this->actingAs(User::factory()->create());

    Livewire::test('notes.index')
        ->assertDontSee('Buy milk');
});

test('[AC-2] captures a title only note with an empty body', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->set('quickCaptureText', 'Just a thought')
        ->call('saveQuickCapture')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('notes', [
        'user_id' => $user->id,
        'title' => 'Just a thought',
        'content' => '',
    ]);
});

test('[AC-2] captured notes are searchable by title and body', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->set('quickCaptureText', "Groceries\nMilk and eggs")
        ->call('saveQuickCapture')
        ->set('search', 'Milk')
        ->assertSee('Groceries');
});

test('[AC-3] keeps a title at the 255 code point limit and moves the overflow into the body', function () {
    $user = User::factory()->create();
    $title = str_repeat('é', 256);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->set('quickCaptureText', $title."\nsecond line")
        ->call('saveQuickCapture')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('notes', [
        'user_id' => $user->id,
        'title' => str_repeat('é', 255),
        'content' => '<p>é</p><p>second line</p>',
    ]);

    expect(mb_strlen(Note::firstOrFail()->title))->toBe(255);
});

test('[AC-3] counts emoji as single code points when limiting the title', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->set('quickCaptureText', str_repeat('😀', 256)."\nbody")
        ->call('saveQuickCapture')
        ->assertHasNoErrors();

    $note = Note::firstOrFail();

    expect(mb_strlen($note->title))->toBe(255)
        ->and($note->content)->toBe('<p>😀</p><p>body</p>');
});

test('[AC-3] normalizes CRLF and trims leading and trailing blank lines', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->set('quickCaptureText', "  First line  \r\n\r\nSecond line  \r\n")
        ->call('saveQuickCapture')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('notes', [
        'user_id' => $user->id,
        'title' => 'First line',
        'content' => '<p>Second line</p>',
    ]);
});

test('[AC-4] stores escaped HTML instead of markup', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->set('quickCaptureText', "Hello <b>world</b>\nSecond & <script>alert('x')</script>")
        ->call('saveQuickCapture')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('notes', [
        'user_id' => $user->id,
        'title' => 'Hello <b>world</b>',
        'content' => '<p>Second &amp; &lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;</p>',
    ]);
});

test('[AC-6] strips escaped HTML from the list preview', function () {
    $user = User::factory()->create();
    $user->notes()->create([
        'title' => 'Preview',
        'content' => '&lt;p&gt;Tom &amp; Jerry&lt;/p&gt;',
    ]);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->assertSee('Tom & Jerry')
        ->assertDontSee('&lt;p&gt;');
});

test('[AC-7] rejects ordinary whitespace and keeps the modal open', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    foreach (['   ', "\t\t", "\n\n"] as $whitespace) {
        Livewire::test('notes.index')
            ->call('openQuickCapture')
            ->set('quickCaptureText', $whitespace)
            ->call('saveQuickCapture')
            ->assertHasErrors(['quickCaptureText' => 'required'])
            ->assertSet('quickCaptureText', $whitespace)
            ->assertSet('showQuickCaptureModal', true)
            ->assertSee('Write something to capture.');
    }

    $this->assertDatabaseCount('notes', 0);
});

test('[AC-9] rejects Unicode whitespace and keeps the modal open', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    foreach (["\u{00A0}", "\u{2003}", "\u{3000}"] as $whitespace) {
        Livewire::test('notes.index')
            ->call('openQuickCapture')
            ->set('quickCaptureText', $whitespace)
            ->call('saveQuickCapture')
            ->assertHasErrors('quickCaptureText')
            ->assertSet('quickCaptureText', $whitespace)
            ->assertSet('showQuickCaptureModal', true)
            ->assertSee('Write something to capture.');
    }

    $this->assertDatabaseCount('notes', 0);
});

test('[AC-8] resets the view to unfiled notes, newest first, after a capture', function () {
    $user = User::factory()->create();
    $folder = $user->folders()->create(['name' => 'Hobbies']);
    $selected = $user->notes()->create(['title' => 'Keep open', 'content' => '<p>Keep this</p>']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectFolder', $folder->id)
        ->call('selectNote', $selected->id)
        ->set('title', 'Unsaved title')
        ->set('content', '<p>Unsaved editor</p>')
        ->set('search', 'needle')
        ->set('sortBy', 'oldest')
        ->call('openQuickCapture')
        ->set('quickCaptureText', "Fresh\nBody")
        ->call('saveQuickCapture')
        ->assertSet('selectedFolderId', null)
        ->assertSet('search', '')
        ->assertSet('sortBy', 'newest')
        ->assertSet('selectedNoteId', $selected->id)
        ->assertSet('title', 'Unsaved title')
        ->assertSet('content', '<p>Unsaved editor</p>')
        ->assertSee('Fresh');
});

test('[AC-8] refuses to open or save quick capture from Trash', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('showTrash')
        ->call('openQuickCapture')
        ->assertSet('showQuickCaptureModal', false)
        ->set('quickCaptureText', 'Should not save')
        ->call('saveQuickCapture')
        ->assertSet('showQuickCaptureModal', false)
        ->assertDontSee('Quick capture');

    $this->assertDatabaseCount('notes', 0);
});

test('[AC-11] reports a danger toast and keeps the text when saving fails', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Note::creating(function (): void {
        throw new RuntimeException('Database unavailable.');
    });

    try {
        Livewire::test('notes.index')
            ->call('openQuickCapture')
            ->set('quickCaptureText', 'Keep me')
            ->call('saveQuickCapture')
            ->assertHasNoErrors()
            ->assertSet('showQuickCaptureModal', true)
            ->assertSet('quickCaptureText', 'Keep me')
            ->assertDispatched('toast-show', function (string $name, array $params) {
                return $params['dataset']['variant'] === 'danger';
            })
            ->assertNotDispatched('toast-show', function (string $name, array $params) {
                return $params['dataset']['variant'] === 'success';
            });
    } finally {
        Note::flushEventListeners();
    }

    $this->assertDatabaseCount('notes', 0);
});

test('[AC-12] captured notes behave like ordinary notes', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('notes.index')
        ->set('quickCaptureText', 'Ordinary')
        ->call('saveQuickCapture');

    $note = Note::firstOrFail();

    $component->call('selectNote', $note->id)
        ->assertSet('title', 'Ordinary')
        ->call('deleteNote');

    $this->assertSoftDeleted('notes', ['id' => $note->id]);
});
