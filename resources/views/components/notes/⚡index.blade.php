<?php

use App\Models\Folder;
use App\Models\Note;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidationValidator;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public ?int $selectedFolderId = null;

    public ?int $selectedNoteId = null;

    public ?int $noteFolderId = null;

    public bool $isTrashView = false;

    public bool $showFolderModal = false;

    public string $folderName = '';

    #[Locked]
    public ?int $editingFolderId = null;

    #[Locked]
    public int $attachmentValidationAttempt = 0;

    public string $title = '';

    public string $content = '';

    public string $search = '';

    public string $sortBy = 'newest';

    public bool $showQuickCaptureModal = false;

    public string $quickCaptureText = '';

    public $attachment = null;

    public function selectFolder(?int $folderId): void
    {
        $this->selectedFolderId = $folderId;
    }

    public function openCreateFolderModal(): void
    {
        $this->editingFolderId = null;
        $this->folderName = '';
        $this->resetValidation('folderName');
        $this->showFolderModal = true;
    }

    public function openRenameFolderModal(int $folderId): void
    {
        $folder = auth()->user()->folders()->findOrFail($folderId);

        $this->editingFolderId = $folder->id;
        $this->folderName = $folder->name;
        $this->resetValidation('folderName');
        $this->showFolderModal = true;
    }

    public function saveFolder(): void
    {
        $user = auth()->user();
        $folder = $this->editingFolderId === null
            ? null
            : $user->folders()->findOrFail($this->editingFolderId);

        try {
            $name = $this->validatedFolderName($user, $folder);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: __('Folder could not be saved. Check the highlighted field.'));

            throw $exception;
        }

        $isCreating = $folder === null;

        try {
            if ($folder === null) {
                $folder = $user->folders()->create(['name' => $name]);
                $this->selectedFolderId = $folder->id;
                $this->isTrashView = false;
            } else {
                $folder->update(['name' => $name]);
            }
        } catch (QueryException $exception) {
            if (! $this->isFolderNameUniqueConflict($exception)) {
                throw $exception;
            }

            $this->addError('folderName', __('A folder with this name already exists.'));
            Flux::toast(variant: 'danger', text: __('Folder could not be saved. Check the highlighted field.'));

            return;
        }

        $this->showFolderModal = false;
        $this->folderName = '';
        $this->editingFolderId = null;
        $this->resetValidation('folderName');

        Flux::toast(
            variant: 'success',
            text: $isCreating ? __('Folder created.') : __('Folder renamed.')
        );
    }

    public function deleteFolder(int $folderId): void
    {
        $folder = auth()->user()->folders()->findOrFail($folderId);

        $folder->delete();

        $this->selectedFolderId = null;
        $this->isTrashView = false;

        if ($this->noteFolderId === $folder->id) {
            $this->noteFolderId = null;
        }

        if ($this->editingFolderId === $folder->id) {
            $this->editingFolderId = null;
            $this->folderName = '';
            $this->showFolderModal = false;
            $this->resetValidation('folderName');
        }

        Flux::toast(variant: 'success', text: __('Folder deleted. Its notes remain in All Notes.'));
    }

    private function validatedFolderName(User $user, ?Folder $folder): string
    {
        $name = Folder::normalizeName($this->folderName);

        $validator = Validator::make(
            ['folderName' => $name],
            ['folderName' => ['required', 'string', 'max:255']],
            [
                'folderName.required' => __('Enter a folder name.'),
                'folderName.max' => __('Folder names cannot exceed 255 characters.'),
            ],
            ['folderName' => __('folder name')]
        );

        $validator->after(function (ValidationValidator $validator) use ($user, $folder, $name): void {
            if ($validator->errors()->has('folderName')) {
                return;
            }

            $folders = $user->folders()->where('name_key', Folder::nameKeyFor($name));

            if ($folder !== null) {
                $folders->where('id', '!=', $folder->id);
            }

            if ($folders->exists()) {
                $validator->errors()->add('folderName', __('A folder with this name already exists.'));
            }
        });

        $validator->validate();

        return $name;
    }

    private function isFolderNameUniqueConflict(QueryException $exception): bool
    {
        $driver = DB::connection()->getDriverName();
        $sqlState = $exception->errorInfo[0] ?? null;
        $message = $exception->getMessage();

        $isUniqueViolation = match ($driver) {
            'pgsql' => $sqlState === '23505',
            'mysql', 'mariadb', 'sqlite', 'sqlsrv' => $sqlState === '23000',
            default => false,
        };

        return $isUniqueViolation && (
            str_contains($message, 'folders_user_id_name_key_unique')
            || str_contains($message, 'folders.user_id, folders.name_key')
        );
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
        if (! $this->selectedNoteId) {
            return;
        }

        try {
            $validated = $this->validate([
                'title' => ['required', 'string', 'max:255'],
                'noteFolderId' => ['nullable', 'integer', Rule::exists('folders', 'id')->where('user_id', auth()->id())],
            ]);
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: __('Note could not be saved. Check the highlighted fields.'));

            throw $e;
        }

        $note = auth()->user()->notes()->findOrFail($this->selectedNoteId);

        $note->update([
            'title' => $validated['title'],
            'content' => $content,
            'folder_id' => $validated['noteFolderId'],
        ]);

        Flux::toast(variant: 'success', text: __('Note saved.'));
    }

    public function createNote(): void
    {
        $user = auth()->user();

        $user->notes()->create([
            'title' => 'Untitled Note',
            'content' => '',
        ]);

        Flux::toast(variant: 'success', text: __('Note created.'));
    }

    public function openQuickCapture(): void
    {
        if ($this->isTrashView) {
            return;
        }

        $this->quickCaptureText = '';
        $this->resetValidation('quickCaptureText');
        $this->showQuickCaptureModal = true;
    }

    public function closeQuickCapture(): void
    {
        $this->showQuickCaptureModal = false;
        $this->quickCaptureText = '';
        $this->resetValidation('quickCaptureText');
    }

    public function saveQuickCapture(): void
    {
        if ($this->isTrashView) {
            return;
        }

        $this->validate(
            ['quickCaptureText' => ['required', 'string']],
            ['quickCaptureText.required' => __('Write something to capture.')]
        );

        $text = $this->normalizeQuickCaptureText($this->quickCaptureText);

        if ($text === '') {
            $this->addError('quickCaptureText', __('Write something to capture.'));

            return;
        }

        [$title, $body] = $this->splitQuickCaptureText($text);

        try {
            auth()->user()->notes()->create([
                'title' => $title,
                'content' => $this->quickCaptureHtml($body),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            Flux::toast(variant: 'danger', text: __('Note could not be captured. Your text is still here.'));

            return;
        }

        $this->showQuickCaptureModal = false;
        $this->quickCaptureText = '';
        $this->resetValidation('quickCaptureText');
        $this->selectedFolderId = null;
        $this->search = '';
        $this->sortBy = 'newest';

        Flux::toast(variant: 'success', text: __('Note captured.'));
    }

    private function normalizeQuickCaptureText(string $text): string
    {
        $normalized = preg_replace(
            '/\A[\p{Z}\x{0009}-\x{000D}\x{0085}\x{FEFF}]+|[\p{Z}\x{0009}-\x{000D}\x{0085}\x{FEFF}]+\z/u',
            '',
            str_replace(["\r\n", "\r"], "\n", $text)
        );

        return $normalized ?? '';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitQuickCaptureText(string $text): array
    {
        $parts = explode("\n", $text, 2);

        $firstLine = $this->normalizeQuickCaptureText($parts[0]);
        $title = mb_substr($firstLine, 0, 255);
        $overflow = mb_substr($firstLine, 255);
        $rest = $parts[1] ?? '';

        $body = $this->normalizeQuickCaptureText($overflow.($rest === '' ? '' : "\n".$rest));

        return [$title, $body];
    }

    private function quickCaptureHtml(string $body): string
    {
        if ($body === '') {
            return '';
        }

        $paragraphs = array_map(
            fn (string $line): string => '<p>'.e($line).'</p>',
            explode("\n", $body)
        );

        return implode('', $paragraphs);
    }

    public function deleteNote(): void
    {
        if (! $this->selectedNoteId) {
            return;
        }

        $note = auth()->user()->notes()->findOrFail($this->selectedNoteId);

        $note->delete();

        $this->selectedNoteId = null;
        $this->title = '';
        $this->content = '';
        $this->noteFolderId = null;

        Flux::toast(variant: 'success', text: __('Note moved to trash.'));
    }

    public function restoreNote(int $noteId): void
    {
        $note = auth()->user()->notes()->onlyTrashed()->findOrFail($noteId);

        $note->restore();

        Flux::toast(variant: 'success', text: __('Note restored.'));
    }

    public function forceDeleteNote(int $noteId): void
    {
        $note = auth()->user()->notes()->onlyTrashed()->findOrFail($noteId);

        $note->forceDelete();

        Flux::toast(variant: 'success', text: __('Note permanently deleted.'));
    }

    public function showTrash(): void
    {
        $this->isTrashView = true;
        $this->selectedFolderId = null;
        $this->selectedNoteId = null;
        $this->title = '';
        $this->content = '';
        $this->noteFolderId = null;
        $this->showQuickCaptureModal = false;
        $this->quickCaptureText = '';
        $this->resetValidation('quickCaptureText');
    }

    public function showNotes(): void
    {
        $this->isTrashView = false;
    }

    protected function attachmentValidationRules(): array
    {
        return [
            'attachment' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,txt,rtf,odt,xls,xlsx,ppt,pptx,csv,jpg,jpeg,png'],
        ];
    }

    public function uploadAttachment(): void
    {
        if (! $this->selectedNoteId) {
            return;
        }

        try {
            $this->validate($this->attachmentValidationRules());
        } catch (ValidationException $e) {
            $this->attachmentValidationAttempt++;
            Flux::toast(variant: 'danger', text: __('Attachment could not be uploaded. Check the highlighted field.'));

            throw $e;
        }

        $note = auth()->user()->notes()->findOrFail($this->selectedNoteId);

        $file = $this->attachment;

        $path = $file->store('note-attachments/'.auth()->id(), 'local');

        try {
            $note->attachments()->create([
                'user_id' => auth()->id(),
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }

        $this->attachment = null;

        Flux::toast(variant: 'success', text: __('Attachment uploaded.'));
    }

    public function deleteAttachment(int $attachmentId): void
    {
        if (! $this->selectedNoteId) {
            return;
        }

        $note = auth()->user()->notes()->findOrFail($this->selectedNoteId);

        $attachment = $note->attachments()->whereKey($attachmentId)->firstOrFail();

        if (Storage::disk('local')->exists($attachment->file_path)) {
            Storage::disk('local')->delete($attachment->file_path);
        }

        $attachment->delete();

        Flux::toast(variant: 'success', text: __('Attachment deleted.'));
    }

    public function render()
    {
        $user = auth()->user();

        $folders = $user->folders()->orderBy('name_key')->get();

        $notesQuery = $user->notes();

        if ($this->isTrashView) {
            $notesQuery->onlyTrashed();
        } elseif ($this->selectedFolderId !== null) {
            $notesQuery->where('folder_id', $this->selectedFolderId);
        }

        if (trim($this->search) !== '') {
            $search = '%'.trim($this->search).'%';

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

        if ($this->selectedNoteId && ! $this->isTrashView) {
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

<div class="p-6"
    x-data="{
        quickCaptureSaving: false,
        quickCaptureCanSave: false,
        openQuickCapture() {
            this.quickCaptureCanSave = false;
            this.$wire.openQuickCapture();
        },
        submitQuickCapture() {
            if (this.quickCaptureSaving || ! this.quickCaptureCanSave) {
                return;
            }

            this.quickCaptureSaving = true;

            this.$wire.saveQuickCapture().finally(() => {
                this.quickCaptureSaving = false;
            });
        },
        handleQuickCaptureKey(event) {
            if (event.key.toLowerCase() !== 'n' || event.repeat || event.isComposing) {
                return;
            }

            if (event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) {
                return;
            }

            if (! (event.target instanceof Element)) {
                return;
            }

            if (event.target.closest('input, textarea, select, [contenteditable]')) {
                return;
            }

            if (document.querySelector('dialog[open], [popover]:popover-open')) {
                return;
            }

            this.openQuickCapture();
        }
    }"
    x-on:keydown.window="handleQuickCaptureKey($event)"
>
    {{-- The only way to do great work is to love what you do. - Steve Jobs --}}
    <div class="grid grid-cols-1 gap-6 md:grid-cols-4">

        {{-- Folders --}}
        <aside class="space-y-2">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">
                    Folders
                </h2>

                <flux:button type="button" variant="ghost" size="sm" icon="plus"
                    wire:click="openCreateFolderModal">
                    {{ __('New folder') }}
                </flux:button>
            </div>

            <button wire:click="showNotes; selectFolder(null)"
                @if ($selectedFolderId === null && !$isTrashView) aria-current="page" @endif
                class="block w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800 {{ $selectedFolderId === null && !$isTrashView ? 'bg-zinc-100 dark:bg-zinc-800' : '' }}">
                All Notes
            </button>

            @foreach ($folders as $folder)
                <div wire:key="folder-{{ $folder->id }}" class="flex items-center gap-1">
                    <button type="button" wire:click="showNotes; selectFolder({{ $folder->id }})"
                        @if ($selectedFolderId === $folder->id && !$isTrashView) aria-current="page" @endif
                        class="block min-w-0 flex-1 truncate rounded-lg px-3 py-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800 {{ $selectedFolderId === $folder->id && !$isTrashView ? 'bg-zinc-100 dark:bg-zinc-800' : '' }}">
                        {{ $folder->name }}
                    </button>

                    <flux:dropdown position="bottom" align="end">
                        <flux:button type="button" variant="ghost" size="sm" icon="ellipsis-horizontal"
                            aria-label="{{ __('Actions for :folder', ['folder' => $folder->name]) }}" />

                        <flux:menu>
                            <flux:menu.item as="button" wire:click="openRenameFolderModal({{ $folder->id }})">
                                {{ __('Rename') }}
                            </flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item as="button" variant="danger"
                                wire:click="deleteFolder({{ $folder->id }})"
                                wire:confirm="{{ __('Permanently delete this folder? Its notes will remain in All Notes without a folder. This cannot be undone.') }}">
                                {{ __('Delete') }}
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
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
                    <div class="flex items-center gap-2">
                        <flux:tooltip content="{{ __('Quick capture') }}" kbd="N" class="hidden md:inline-flex">
                            <button type="button" x-on:click="openQuickCapture()"
                                class="rounded-lg border px-4 py-2 text-sm font-medium">
                                {{ __('Quick capture') }}
                            </button>
                        </flux:tooltip>

                        <button type="button" wire:click="createNote"
                            class="rounded-lg bg-black px-4 py-2 text-sm font-medium text-white">
                            New Note
                        </button>
                    </div>
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
                                {{ \Illuminate\Support\Str::limit(html_entity_decode(strip_tags($note->content ?? '')), 120) }}
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

                    @error('title')
                        <p class="-mt-3 mb-3 text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    <select wire:model="noteFolderId" class="mb-4 w-full rounded-lg border px-3 py-2">
                        <option value="">No Folder</option>

                        @foreach ($folders as $folder)
                            <option value="{{ $folder->id }}">
                                {{ $folder->name }}
                            </option>
                        @endforeach
                    </select>

                    @error('noteFolderId')
                        <p class="-mt-3 mb-3 text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    <div wire:key="tiptap-editor-{{ $selectedNoteId }}" x-data="createTiptapComponent(@js($content))" wire:ignore @save-note-requested.window="$wire.saveNote(getContent())">
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

                            <button type="button" @mousedown.prevent @click="toggleHighlight()"
                                :class="{ 'bg-zinc-200': activeFormats.highlight }" class="rounded px-3 py-1 text-sm"
                                title="Highlight">
                                🖍️
                            </button>

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
                        <div x-ref="editor" class="w-full rounded-lg border"></div>
                    </div>

                    <div class="mt-6 border-t border-zinc-200 pt-4">
                        <h3 class="mb-3 text-sm font-semibold text-zinc-800">
                            Attachments
                        </h3>

                        <div class="mb-4 overflow-hidden rounded-lg border border-zinc-200">
                            @forelse ($selectedNote->attachments as $noteAttachment)
                                <div class="flex items-center justify-between gap-3 p-3 {{ !$loop->last ? 'border-b border-zinc-200' : '' }}">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium">{{ $noteAttachment->original_name }}</p>
                                        <p class="text-xs text-zinc-500">{{ number_format($noteAttachment->file_size / 1024, 1) }} KB</p>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2">
                                        <a href="{{ route('note-attachments.download', $noteAttachment) }}"
                                            class="rounded-lg border px-3 py-1 text-sm font-medium">
                                            Download
                                        </a>
                                        <button type="button" wire:click="deleteAttachment({{ $noteAttachment->id }})"
                                            wire:confirm="Delete this attachment?"
                                            class="rounded-lg border border-red-200 px-3 py-1 text-sm font-medium text-red-600">
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            @empty
                                <p class="p-3 text-sm text-zinc-500">No attachments yet.</p>
                            @endforelse
                        </div>

                        <form wire:submit="uploadAttachment" class="flex flex-wrap items-center gap-3">
                            <input type="file" wire:model="attachment"
                                x-on:cancel="$wire.removeUpload('attachment', @js($attachment?->getFilename()))"
                                accept=".pdf,.doc,.docx,.txt,.rtf,.odt,.xls,.xlsx,.ppt,.pptx,.csv,.jpg,.jpeg,.png"
                                class="block max-w-full text-sm text-zinc-600" />

                            <button type="submit" wire:loading.attr="disabled"
                                wire:target="uploadAttachment, attachment"
                                class="rounded-lg bg-black px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                                Upload file
                            </button>
                        </form>

                        <div wire:loading wire:target="attachment" class="mt-2 text-sm text-zinc-500">
                            Uploading file to the server...
                        </div>

                        @error('attachment')
                            <p wire:key="attachment-validation-{{ $attachmentValidationAttempt }}"
                                x-data="{ visible: true }"
                                x-init="setTimeout(() => visible = false, 5000)"
                                x-show="visible"
                                x-transition.opacity
                                class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Save --}}
                    <button type="button" @click="$dispatch('save-note-requested')"
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

    @if (!$isTrashView)
        <button type="button" x-on:click="openQuickCapture()"
            class="fixed bottom-[calc(1.5rem_+_env(safe-area-inset-bottom))] right-[calc(1.5rem_+_env(safe-area-inset-right))] z-40 inline-flex h-14 w-14 items-center justify-center rounded-full bg-black text-white shadow-lg md:hidden"
            aria-label="{{ __('Quick capture') }}">
            <flux:icon.plus class="size-6" />
        </button>
    @endif

    <flux:modal name="manage-folder" wire:model="showFolderModal" class="min-w-0 w-full max-w-md">
        <form wire:submit="saveFolder" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $editingFolderId === null ? __('Create a folder') : __('Rename folder') }}
                </flux:heading>
                <flux:subheading>
                    {{ __('Folder names are unique within your account.') }}
                </flux:subheading>
            </div>

            <flux:field>
                <flux:label for="folder-name">{{ __('Folder name') }}</flux:label>
                <flux:input id="folder-name" wire:model="folderName" autofocus />
                <flux:error name="folderName" />
            </flux:field>

            <div class="flex flex-wrap justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">
                        {{ __('Cancel') }}
                    </flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveFolder">
                    {{ $editingFolderId === null ? __('Create folder') : __('Save changes') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="quick-capture" wire:model="showQuickCaptureModal" wire:close="closeQuickCapture"
        class="min-w-0 w-full max-w-md">
        <form x-on:submit.prevent="submitQuickCapture()" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ __('Capture a thought') }}
                </flux:heading>
                <flux:subheading>
                    {{ __('The first line becomes the title.') }}
                </flux:subheading>
            </div>

            <flux:field>
                <flux:label for="quick-capture-text">{{ __('Note') }}</flux:label>
                <flux:textarea id="quick-capture-text" wire:model="quickCaptureText"
                    x-on:input="quickCaptureCanSave = $event.target.value.trim() !== ''"
                    x-on:keydown.ctrl.enter.prevent="submitQuickCapture()"
                    x-on:keydown.meta.enter.prevent="submitQuickCapture()" autofocus rows="5"
                    placeholder="{{ __('What is on your mind?') }}" />
                <p x-show="!quickCaptureCanSave" class="text-sm text-zinc-500">
                    {{ __('Write something to capture.') }}
                </p>
                <flux:error name="quickCaptureText" />
            </flux:field>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-zinc-500">
                    <kbd class="rounded border px-1">Ctrl</kbd>/<kbd class="rounded border px-1">Cmd</kbd> +
                    <kbd class="rounded border px-1">Enter</kbd> {{ __('to save') }}
                    <span class="mx-2">·</span>
                    <kbd class="rounded border px-1">N</kbd> {{ __('to open') }}
                </p>

                <div class="flex gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost" x-on:click="$wire.closeQuickCapture()">
                            {{ __('Cancel') }}
                        </flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary"
                        x-bind:disabled="quickCaptureSaving || !quickCaptureCanSave">
                        {{ __('Save') }}
                    </flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
