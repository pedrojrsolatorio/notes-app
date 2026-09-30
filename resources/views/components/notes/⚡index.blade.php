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
    public string $search = '';
    public string $sortBy = 'newest';

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

    public function saveNote(string $content = ''): void
    {
        if (!$this->selectedNoteId) {
            return;
        }

        $note = auth()->user()->notes()->findOrFail($this->selectedNoteId);

        $note->update([
            'title' => $this->title,
            'content' => $content,
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

    public function restoreNote(int $noteId): void
    {
        $note = auth()->user()->notes()->onlyTrashed()->findOrFail($noteId);

        $note->restore();
    }

    public function forceDeleteNote(int $noteId): void
    {
        $note = auth()->user()->notes()->onlyTrashed()->findOrFail($noteId);

        $note->forceDelete();
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

        if (trim($this->search) !== '') {
            $search = '%' . trim($this->search) . '%';

            $notesQuery->where(function ($query) use ($search) {
                $query->where('title', 'like', $search)->orWhere('content', 'like', $search);
            });
        }

        switch ($this->sortBy) {
            case 'oldest':
                $notesQuery->oldest();
                break;

            case 'title_asc':
                $notesQuery->orderByRaw('LOWER(title) ASC');
                break;

            case 'title_desc':
                $notesQuery->orderByRaw('LOWER(title) DESC');
                break;

            default:
                $notesQuery->latest();
                break;
        }

        $notes = $notesQuery->get();

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
            <div class="mb-6 flex items-center justify-between gap-4">
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

            <div class="mb-6">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search notes..."
                    class="w-full rounded-lg border px-4 py-2">

                <select wire:model.live="sortBy" class="rounded-lg border px-4 py-2 sm:w-48">
                    <option value="newest">Newest</option>
                    <option value="oldest">Oldest</option>
                    <option value="title_asc">Title A-Z</option>
                    <option value="title_desc">Title Z-A</option>
                </select>
            </div>

            @if ($notes->isEmpty())
                <p class="text-zinc-500">
                    No notes yet.
                </p>
            @else
                <div class="space-y-3">
                    @foreach ($notes as $note)
                        <article @if (!$isTrashView) wire:click="selectNote({{ $note->id }})" @endif
                            class="{{ !$isTrashView ? 'cursor-pointer' : '' }} rounded-lg border p-4 hover:bg-zinc-50 dark:hover:bg-zinc-900">
                            <h2 class="font-semibold">
                                {{ $note->title }}
                            </h2>

                            <p class="mt-1 text-sm text-zinc-500">
                                {{ \Illuminate\Support\Str::limit(strip_tags($note->content ?? ''), 120) }}
                            </p>

                            @if ($isTrashView)
                                <button type="button" wire:click="restoreNote({{ $note->id }})"
                                    class="mt-3 rounded-lg border px-3 py-2 text-sm font-medium">
                                    Restore
                                </button>
                                <button type="button" wire:click="forceDeleteNote({{ $note->id }})"
                                    wire:confirm="Permanently delete this note? This cannot be undone."
                                    class="mt-3 rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white">
                                    Delete Permanently
                                </button>
                            @endif
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

                    <div wire:key="tiptap-editor-{{ $selectedNoteId }}" x-data="createTiptapComponent(@js($content))" wire:ignore>
                        {{-- Toolbar --}}
                        <div class="mb-2 flex gap-1 rounded-lg border p-1">
                            <button type="button" @mousedown.prevent @click="toggleBold()"
                                :class="{ 'bg-zinc-200': activeFormats.bold }" class="rounded px-3 py-1 font-bold">
                                B
                            </button>

                            <button type="button" @mousedown.prevent @click="toggleItalic()"
                                :class="{ 'bg-zinc-200': activeFormats.italic }" class="rounded px-3 py-1 italic">
                                I
                            </button>

                            <button type="button" @mousedown.prevent @click="toggleUnderline()"
                                :class="{ 'bg-zinc-200': activeFormats.underline }" class="rounded px-3 py-1 underline">
                                U
                            </button>

                            <button type="button" @mousedown.prevent @click="toggleStrike()"
                                :class="{ 'bg-zinc-200': activeFormats.strike }" class="rounded px-3 py-1 line-through">
                                S
                            </button>

                            <button type="button" @mousedown.prevent @click="toggleCodeBlock()"
                                :class="{ 'bg-zinc-200': activeFormats.codeBlock }" class="rounded px-3 py-1 text-sm"
                                title="Code block">
                                &lt;/&gt;
                            </button>

                            <button type="button" @mousedown.prevent @click="toggleBulletList()"
                                :class="{ 'bg-zinc-200': activeFormats.bulletList }" class="rounded px-3 py-1">
                                • List
                            </button>

                            <button type="button" @mousedown.prevent @click="toggleOrderedList()"
                                :class="{ 'bg-zinc-200': activeFormats.orderedList }" class="rounded px-3 py-1">
                                1. List
                            </button>

                            <button type="button" @mousedown.prevent @click="toggleTaskList()"
                                :class="{ 'bg-zinc-200': activeFormats.taskList }" class="rounded px-3 py-1 text-sm"
                                title="Checklist">
                                ☑
                            </button>

                            <button type="button" @mousedown.prevent @click="setParagraph()"
                                :class="{ 'bg-zinc-200': activeFormats.paragraph }" class="rounded px-3 py-1 text-sm">
                                P
                            </button>

                            <button type="button" @mousedown.prevent @click="setHeading(1)"
                                :class="{ 'bg-zinc-200': activeFormats.heading1 }"
                                class="rounded px-3 py-1 text-sm font-bold">
                                H1
                            </button>

                            <button type="button" @mousedown.prevent @click="setHeading(2)"
                                :class="{ 'bg-zinc-200': activeFormats.heading2 }"
                                class="rounded px-3 py-1 text-sm font-bold">
                                H2
                            </button>

                            <button type="button" @mousedown.prevent @click="setHeading(3)"
                                :class="{ 'bg-zinc-200': activeFormats.heading3 }"
                                class="rounded px-3 py-1 text-sm font-bold">
                                H3
                            </button>

                            <select :value="activeFormats.fontSize" @change="setFontSize($event.target.value)"
                                class="rounded border border-zinc-300 bg-white px-2 py-1 text-sm" title="Font size">
                                <option value="mixed" disabled>—</option>
                                <option value="12px">12</option>
                                <option value="14px">14</option>
                                <option value="16px">16</option>
                                <option value="18px">18</option>
                                <option value="20px">20</option>
                                <option value="24px">24</option>
                                <option value="28px">28</option>
                                <option value="32px">32</option>
                            </select>

                            <input type="color" :value="activeFormats.color" @input="setColor($event.target.value)"
                                class="h-8 w-10 cursor-pointer rounded border border-zinc-300 bg-white p-1"
                                title="Font color" />

                            <button type="button" @mousedown.prevent @click="setLink()"
                                :class="{ 'bg-zinc-200': activeFormats.link }" class="rounded px-3 py-1 text-sm"
                                title="Link">
                                🔗
                            </button>

                            <button type="button" @mousedown.prevent @click="setTextAlign('left')"
                                :class="{ 'bg-zinc-200': activeFormats.alignLeft }" class="rounded px-3 py-1 text-sm"
                                title="Align left">
                                ⬅
                            </button>

                            <button type="button" @mousedown.prevent @click="setTextAlign('center')"
                                :class="{ 'bg-zinc-200': activeFormats.alignCenter }"
                                class="rounded px-3 py-1 text-sm" title="Align center">
                                ↔
                            </button>

                            <button type="button" @mousedown.prevent @click="setTextAlign('right')"
                                :class="{ 'bg-zinc-200': activeFormats.alignRight }" class="rounded px-3 py-1 text-sm"
                                title="Align right">
                                ➡
                            </button>

                            <button type="button" @mousedown.prevent @click="setTextAlign('justify')"
                                :class="{ 'bg-zinc-200': activeFormats.alignJustify }"
                                class="rounded px-3 py-1 text-sm" title="Justify">
                                ☰
                            </button>

                            <button type="button" @mousedown.prevent @click="insertTable()"
                                class="rounded px-3 py-1 text-sm" title="Insert table">
                                ▦
                            </button>

                            <div x-show="activeFormats.table" x-data="{ tableMenuOpen: false }" class="relative">
                                <button type="button" @click="tableMenuOpen = !tableMenuOpen"
                                    class="rounded px-3 py-1 text-sm" title="Table options">
                                    Table ▾
                                </button>

                                <div x-show="tableMenuOpen" @click.outside="tableMenuOpen = false"
                                    class="absolute left-0 top-full z-50 mt-1 w-48 rounded-lg border border-zinc-200 bg-white p-1 shadow-lg">
                                    <button type="button" @click="addTableRowBefore(); tableMenuOpen = false"
                                        class="block w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100">
                                        Add row above
                                    </button>

                                    <button type="button" @click="addTableRowAfter(); tableMenuOpen = false"
                                        class="block w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100">
                                        Add row below
                                    </button>

                                    <button type="button" @click="deleteTableRow(); tableMenuOpen = false"
                                        class="block w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100">
                                        Delete row
                                    </button>

                                    <div class="my-1 border-t border-zinc-200"></div>

                                    <button type="button" @click="addTableColumnBefore(); tableMenuOpen = false"
                                        class="block w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100">
                                        Add column left
                                    </button>

                                    <button type="button" @click="addTableColumnAfter(); tableMenuOpen = false"
                                        class="block w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100">
                                        Add column right
                                    </button>

                                    <button type="button" @click="deleteTableColumn(); tableMenuOpen = false"
                                        class="block w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100">
                                        Delete column
                                    </button>

                                    <div class="my-1 border-t border-zinc-200"></div>

                                    <button type="button" @click="deleteTable(); tableMenuOpen = false"
                                        class="block w-full rounded px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50">
                                        Delete table
                                    </button>
                                </div>
                            </div>
                        </div>

                        {{-- Editor --}}
                        <div x-ref="editor" class="min-h-64 w-full rounded-lg border p-3 focus:outline-none"></div>

                        {{-- Save --}}
                        <button type="button" @click="$wire.saveNote(getContent())"
                            class="mt-4 rounded-lg bg-black px-4 py-2 text-sm font-medium text-white">
                            Save
                        </button>
                    </div>

                    <button type="button" wire:click="deleteNote" wire:confirm="Move this to Trash?"
                        class="mt-4 rounded-lg border px-4 py-2 text-sm font-medium">
                        Delete
                    </button>
                </div>
            @endif
        </main>

    </div>
</div>
