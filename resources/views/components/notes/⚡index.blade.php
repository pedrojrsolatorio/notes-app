<?php

use App\Models\Note;
use Livewire\Component;

new class extends Component {
    public ?int $selectedFolderId = null;
    public ?int $selectedNoteId = null;
    public ?int $noteFolderId = null;
    public bool $isTrashView = false;

    public string $title = '';
    public string $content = '';

    public function selectFolder(?int $folderId): void
    {
        $this->selectedFolderId = $folderId;
    }

    public function selectNote(int $noteId): void
    {
        $note = auth()->user()->notes()->findOrFail($noteId);

        $this->selectedNoteId = $note->id;
        $this->title = $note->title;
        $this->content = $note->content ?? '';
        $this->noteFolderId = $note->folder_id;
    }

    public function saveNote(): void
    {
        if (!$this->selectedNoteId) {
            return;
        }

        $note = auth()->user()->notes()->findOrFail($this->selectedNoteId);

        $note->update([
            'title' => $this->title,
            'content' => $this->content,
            'folder_id' => $this->noteFolderId,
        ]);
    }

    public function createNote(): void
    {
        $user = auth()->user();

        $user->notes()->create([
            'title' => 'Untitled Note',
            'content' => '',
        ]);
    }

    public function deleteNote(): void
    {
        if (!$this->selectedNoteId) {
            return;
        }

        $note = auth()->user()->notes()->findOrFail($this->selectedNoteId);

        $note->delete();

        $this->selectedNoteId = null;
        $this->title = '';
        $this->content = '';
        $this->noteFolderId = null;
    }

    public function showTrash(): void
    {
        $this->isTrashView = true;
        $this->selectedFolderId = null;
        $this->selectedNoteId = null;
        $this->title = '';
        $this->content = '';
        $this->noteFolderId = null;
    }

    public function showNotes(): void
    {
        $this->isTrashView = false;
    }

    public function render()
    {
        $user = auth()->user();

        $folders = $user->folders()->orderBy('name')->get();

        $notesQuery = $user->notes();

        if ($this->isTrashView) {
            $notesQuery->onlyTrashed();
        } elseif ($this->selectedFolderId !== null) {
            $notesQuery->where('folder_id', $this->selectedFolderId);
        }

        $notes = $notesQuery->latest()->get();

        $selectedNote = null;

        if ($this->selectedNoteId && !$this->isTrashView) {
            $selectedNote = $user->notes()->find($this->selectedNoteId);
        }

        return $this->view([
            'folders' => $folders,
            'notes' => $notes,
            'selectedNote' => $selectedNote,
        ]);
    }
};
?>

<div class="p-6">
    {{-- The only way to do great work is to love what you do. - Steve Jobs --}}
    <div class="grid grid-cols-1 gap-6 md:grid-cols-4">

        {{-- Folders --}}
        <aside class="space-y-2">
            <h2 class="text-lg font-semibold">
                Folders
            </h2>

            <button wire:click="showNotes; selectFolder(null)"
                class="block w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800">
                All Notes
            </button>

            @foreach ($folders as $folder)
                <button wire:click="showNotes; selectFolder({{ $folder->id }})"
                    class="block w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    {{ $folder->name }}
                </button>
            @endforeach

            <button wire:click="showTrash"
                class="block w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800">
                Trash
            </button>
        </aside>

        {{-- Notes --}}
        <main class="md:col-span-3">
            <div class="mb-6 flex items-center justify-between">
                <h1 class="text-2xl font-bold">
                    {{ $isTrashView ? 'Trash' : 'Notes' }}
                </h1>

                @if (!$isTrashView)
                    <button type="button" wire:click="createNote"
                        class="rounded-lg bg-black px-4 py-2 text-sm font-medium text-white">
                        New Note
                    </button>
                @endif
            </div>

            @if ($notes->isEmpty())
                <p class="text-zinc-500">
                    No notes yet.
                </p>
            @else
                <div class="space-y-3">
                    @foreach ($notes as $note)
                        <article wire:click="selectNote({{ $note->id }})"
                            class="cursor-pointer rounded-lg border p-4 hover:bg-zinc-50 dark:hover:bg-zinc-900">
                            <h2 class="font-semibold">
                                {{ $note->title }}
                            </h2>

                            <p class="mt-1 text-sm text-zinc-500">
                                {{ $note->content }}
                            </p>
                        </article>
                    @endforeach
                </div>
            @endif

            @if ($selectedNote)
                <div class="mt-8 rounded-lg border p-6">
                    <h2 class="mb-4 text-xl font-semibold">
                        Edit Note
                    </h2>

                    <input type="text" wire:model="title" class="mb-4 w-full rounded-lg border px-3 py-2">

                    <select wire:model="noteFolderId" class="mb-4 w-full rounded-lg border px-3 py-2">
                        <option value="">No Folder</option>

                        @foreach ($folders as $folder)
                            <option value="{{ $folder->id }}">
                                {{ $folder->name }}
                            </option>
                        @endforeach
                    </select>

                    <textarea wire:model="content" class="h-64 w-full rounded-lg border p-3"></textarea>

                    <button type="button" wire:click="saveNote"
                        class="mt-4 rounded-lg bg-black px-4 py-2 text-sm font-medium text-white">
                        Save
                    </button>

                    <button type="button" wire:click="deleteNote" wire:confirm="Move this to Trash?"
                        class="mt-4 rounded-lg border px-4 py-2 text-sm font-medium">
                        Delete
                    </button>
                </div>
            @endif
        </main>

    </div>
</div>
