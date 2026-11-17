<?php

use App\Models\User;
use Livewire\Livewire;

test('[AC-4] renders one main landmark and a skip link on the notes page', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)
        ->get(route('notes.index'))
        ->getContent();

    expect(substr_count($html, 'role="main"'))->toBe(1)
        ->and($html)->toContain('href="#main-content"')
        ->toContain('id="main-content"')
        ->toContain('Skip to content');
});

test('[AC-2] labels the search, sort, title, and folder fields', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Accessible note']);

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->call('selectNote', $note->id)
        ->assertSeeHtml('for="notes-search"')
        ->assertSeeHtml('id="notes-search"')
        ->assertSeeHtml('for="notes-sort"')
        ->assertSeeHtml('id="notes-sort"')
        ->assertSeeHtml('for="note-title"')
        ->assertSeeHtml('id="note-title"')
        ->assertSeeHtml('for="note-folder"')
        ->assertSeeHtml('id="note-folder"');
});

test('[AC-3] exposes a labeled toolbar with pressed state bindings', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Formatted note']);

    $this->actingAs($user);

    $component = Livewire::test('notes.index')->call('selectNote', $note->id);
    $html = $component->html();

    expect($html)->toContain('role="toolbar"')
        ->toContain('aria-label="Formatting"')
        ->toContain('aria-describedby="note-toolbar-help"')
        ->toContain('activeFormats.bold')
        ->toContain('activeFormats.italic')
        ->toContain('More');
});

test('[AC-9] renders a persistent status region on the notes page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->assertSeeHtml('role="status"')
        ->assertSeeHtml('aria-live="polite"')
        ->assertSeeHtml('x-text="connectionStatus"');
});

test('[AC-13] exposes the quick capture shortcut to assistive technology', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('notes.index')
        ->assertSeeHtml('aria-keyshortcuts="Alt+Shift+N"')
        ->assertSee('Alt+Shift+N');
});

test('[AC-14] exposes narrow, medium, wide, and reset table width controls', function () {
    $user = User::factory()->create();
    $note = $user->notes()->create(['title' => 'Table note']);

    $this->actingAs($user);

    $component = Livewire::test('notes.index')->call('selectNote', $note->id);
    $html = $component->html();

    expect($html)->toContain('Narrow')
        ->toContain('Medium')
        ->toContain('Wide')
        ->toContain('Reset')
        ->toContain('setTableWidth(50)')
        ->toContain('setTableWidth(75)')
        ->toContain('setTableWidth(100)')
        ->toContain('setTableWidth(null)');
});
