<?php

use App\Models\Folder;
use App\Models\Note;
use App\Models\User;
use Carbon\CarbonImmutable;
use App\Jobs\SendNoteReminder;
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

    #[Locked]
    public ?int $reminderNoteId = null;

    #[Locked]
    public ?string $reminderAtUtc = null;

    #[Locked]
    public bool $unavailableNote = false;

    #[Locked]
    public int $reminderFormKey = 0;

    public bool $showReminderModal = false;

    public function mount(): void
    {
        $portableId = request()->query('note');

        if ($portableId === null) {
            return;
        }

        if (! is_string($portableId) || $portableId === '') {
            $this->unavailableNote = true;

            return;
        }

        $note = auth()->user()->notes()->where('portable_id', $portableId)->first();

        if ($note === null) {
            $this->unavailableNote = true;

            return;
        }

        $this->selectNote($note->id);
    }

    public function openReminderModal(int $noteId): void
    {
        $note = auth()->user()->notes()->findOrFail($noteId);

        $this->reminderNoteId = $note->id;
        $this->reminderAtUtc = $note->reminder_at?->utc()->format('Y-m-d\TH:i:s.000\Z');
        $this->reminderFormKey++;
        $this->resetValidation('reminderAtUtc');
        $this->showReminderModal = true;
    }

    public function saveReminder(int $noteId, mixed $reminderAtUtc): void
    {
        try {
            $validated = Validator::make(
                ['reminderAtUtc' => $reminderAtUtc],
                [
                    'reminderAtUtc' => [
                        'required',
                        'string',
                        'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00\.000Z\z/',
                        'date',
                    ],
                ],
                [
                    'reminderAtUtc.required' => __('Choose a date and time.'),
                    'reminderAtUtc.regex' => __('Choose a valid date and time.'),
                    'reminderAtUtc.date' => __('Choose a valid date and time.'),
                ],
            )->validate();

            $reminderAt = CarbonImmutable::parse($validated['reminderAtUtc'])->utc();

            if ($reminderAt->lessThanOrEqualTo(now('UTC'))) {
                throw ValidationException::withMessages([
                    'reminderAtUtc' => __('Choose a time in the future.'),
                ]);
            }

            DB::transaction(function () use ($noteId, $reminderAt): void {
                $note = auth()->user()->notes()->withTrashed()->lockForUpdate()->findOrFail($noteId);

                if ($note->trashed()) {
                    throw ValidationException::withMessages([
                        'reminderAtUtc' => __('Reminders cannot be changed in Trash.'),
                    ]);
                }

                $hasReminderData = $note->reminder_at !== null
                    || $note->reminder_status !== null
                    || $note->reminder_generation !== null;

                if (
                    $hasReminderData
                    && (
                        $note->reminder_at === null
                        || $note->reminder_status === null
                        || $note->reminder_generation === null
                    )
                ) {
                    throw new \LogicException('A note reminder has incomplete state.');
                }

                if ($note->reminder_at?->equalTo($reminderAt)) {
                    return;
                }

                DB::table('notes')->where('id', $note->id)->update([
                    'reminder_at' => $reminderAt->format('Y-m-d H:i:s'),
                    'reminder_status' => 'scheduled',
                    'reminder_generation' => (string) \Illuminate\Support\Str::uuid(),
                ]);
            });

            $this->showReminderModal = false;
            $this->reminderNoteId = null;
            $this->reminderAtUtc = null;
            $this->resetValidation('reminderAtUtc');

            Flux::toast(variant: 'success', text: __('Reminder saved.'));
        } catch (ValidationException $exception) {
            $this->dispatch('validation-failed', field: 'reminderAtUtc');
            Flux::toast(variant: 'danger', text: $exception->validator->errors()->first());

            throw $exception;
        }
    }

    public function clearReminder(int $noteId): void
    {
        DB::transaction(function () use ($noteId): void {
            $note = auth()->user()->notes()->withTrashed()->lockForUpdate()->findOrFail($noteId);

            DB::table('notes')->where('id', $note->id)->update([
                'reminder_at' => null,
                'reminder_status' => null,
                'reminder_generation' => null,
            ]);
        });

        Flux::toast(variant: 'success', text: __('Reminder cleared.'));
    }

    public function retryReminder(int $noteId): void
    {
        $result = DB::transaction(function () use ($noteId): string {
            $note = auth()->user()->notes()->withTrashed()->lockForUpdate()->findOrFail($noteId);

            if ($note->trashed()) {
                return 'trash';
            }

            if ($note->reminder_status !== 'failed') {
                return 'not_failed';
            }

            if (! $note->user?->hasVerifiedEmail()) {
                return 'unverified';
            }

            if ($note->reminder_at === null || $note->reminder_generation === null) {
                throw new \LogicException('A failed note reminder has incomplete state.');
            }

            $generation = (string) \Illuminate\Support\Str::uuid();

            DB::table('notes')->where('id', $note->id)->update([
                'reminder_status' => 'queued',
                'reminder_generation' => $generation,
            ]);

            SendNoteReminder::dispatch($note->id, $generation)->beforeCommit();

            return 'queued';
        });

        if ($result === 'queued') {
            Flux::toast(variant: 'success', text: __('Reminder retry queued.'));

            return;
        }

        $message = match ($result) {
            'trash' => __('Reminders cannot be retried in Trash.'),
            'unverified' => __('Verify your email address before retrying this reminder.'),
            default => __('This reminder is not available to retry.'),
        };

        Flux::toast(variant: 'danger', text: $message);
    }

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
            $this->dispatch('validation-failed', field: 'folderName');
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

        $this->dispatch('focus-target', id: $this->selectedNoteId ? 'note-folder' : 'note-list-heading');

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
        $this->unavailableNote = false;

        $note = auth()->user()->notes()->findOrFail($noteId);

        $this->selectedNoteId = $note->id;
        $this->title = $note->title;
        $this->content = $note->content ?? '';
        $this->noteFolderId = $note->folder_id;

        $this->dispatch('note-selected');
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
            $this->dispatch('validation-failed', field: $e->validator->errors()->keys()[0] ?? null);
            Flux::toast(variant: 'danger', text: __('Note could not be saved. Check the highlighted fields.'));

            throw $e;
        }

        $note = auth()->user()->notes()->findOrFail($this->selectedNoteId);

        $note->update([
            'title' => $validated['title'],
            'content' => $content,
            'folder_id' => $validated['noteFolderId'],
        ]);

        $this->dispatch('note-saved');
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

        $this->dispatch('quick-capture-opened');
    }

    public function closeQuickCapture(): void
    {
        $this->showQuickCaptureModal = false;
        $this->quickCaptureText = '';
        $this->resetValidation('quickCaptureText');
        $this->dispatch('quick-capture-closed');
    }

    public function saveQuickCapture(): void
    {
        if ($this->isTrashView) {
            return;
        }

        try {
            $this->validate(
                ['quickCaptureText' => ['required', 'string']],
                ['quickCaptureText.required' => __('Write something to capture.')]
            );
        } catch (ValidationException $exception) {
            $this->dispatch('validation-failed', field: 'quickCaptureText');

            throw $exception;
        }

        $text = $this->normalizeQuickCaptureText($this->quickCaptureText);

        if ($text === '') {
            $this->addError('quickCaptureText', __('Write something to capture.'));
            $this->dispatch('validation-failed', field: 'quickCaptureText');

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
        $this->dispatch('quick-capture-closed');

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

        $ids = $this->noteListQuery(auth()->user())->pluck('id')->all();
        $index = array_search($note->id, $ids, true);
        $nextId = $index === false ? null : ($ids[$index + 1] ?? $ids[$index - 1] ?? null);

        $note->delete();

        $this->selectedNoteId = null;
        $this->title = '';
        $this->content = '';
        $this->noteFolderId = null;

        $this->dispatch('focus-target', id: $nextId ? 'note-row-'.$nextId : 'note-list-heading');

        Flux::toast(variant: 'success', text: __('Note moved to trash.'));
    }

    public function restoreNote(int $noteId): void
    {
        DB::transaction(function () use ($noteId): void {
            $note = auth()->user()->notes()->onlyTrashed()->lockForUpdate()->findOrFail($noteId);

            if ($note->reminder_at?->lessThanOrEqualTo(now('UTC'))) {
                DB::table('notes')->where('id', $note->id)->update([
                    'reminder_at' => null,
                    'reminder_status' => null,
                    'reminder_generation' => null,
                ]);
            }

            $note->restore();
        });

        $this->dispatch('focus-target', id: 'note-list-heading');

        Flux::toast(variant: 'success', text: __('Note restored.'));
    }

    public function forceDeleteNote(int $noteId): void
    {
        $note = auth()->user()->notes()->onlyTrashed()->findOrFail($noteId);

        $ids = $this->noteListQuery(auth()->user())->pluck('id')->all();
        $index = array_search($note->id, $ids, true);
        $nextId = $index === false ? null : ($ids[$index + 1] ?? $ids[$index - 1] ?? null);

        $note->forceDelete();

        $this->dispatch('focus-target', id: $nextId ? 'restore-note-'.$nextId : 'note-list-heading');

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

    use \App\Concerns\AttachmentValidationRules;

    public function uploadAttachment(): void
    {
        if (! $this->selectedNoteId) {
            return;
        }

        try {
            $this->validate($this->attachmentValidationRules());
        } catch (ValidationException $e) {
            $this->attachmentValidationAttempt++;
            $this->dispatch('validation-failed', field: 'attachment');
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

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Note>
     */
    private function noteListQuery(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $notesQuery = $user->notes()->getQuery();

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

        return $notesQuery;
    }

    public function render()
    {
        $user = auth()->user();

        $folders = $user->folders()->orderBy('name_key')->get();

        $notes = $this->noteListQuery($user)->get();

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

<div class="p-4 sm:p-6"
    x-data="{
        quickCaptureSaving: false,
        quickCaptureCanSave: false,
        quickCaptureOpenerId: null,
        mobileView: 'list',
        connectionStatus: navigator.onLine ? @js(__('Online')) : @js(__('Offline')),
        openQuickCapture() {
            this.quickCaptureCanSave = false;
            this.quickCaptureOpenerId = document.activeElement?.id ?? null;
            this.$wire.openQuickCapture();
        },
        returnFromQuickCapture() {
            this.$nextTick(() => {
                const opener = this.quickCaptureOpenerId
                    ? document.getElementById(this.quickCaptureOpenerId)
                    : null;

                (opener ?? this.$refs.quickCaptureButton ?? this.$refs.quickCaptureFloating ?? this.$refs.noteListHeading)?.focus();
                this.quickCaptureOpenerId = null;
            });
        },
        focusTarget(id) {
            if (window.matchMedia('(max-width: 1023px)').matches) {
                this.mobileView = id === 'note-folder' ? 'editor' : 'list';
            }

            this.$nextTick(() => {
                const target = id ? document.getElementById(id) : null;

                (target ?? this.$refs.noteListHeading)?.focus();
            });
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
        handleShortcut(event) {
            if (event.key.toLowerCase() !== 'n' || event.repeat || event.isComposing) {
                return;
            }

            if (! event.altKey || ! event.shiftKey || event.ctrlKey || event.metaKey) {
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

            event.preventDefault();
            this.openQuickCapture();
        },
        backToList() {
            this.mobileView = 'list';

            this.$nextTick(() => {
                const current = document.querySelector('[data-note-list] [aria-current=\'true\']');

                (current ?? this.$refs.noteListHeading)?.focus();
            });
        },
        focusValidationField(field) {
            const fields = {
                title: 'note-title',
                noteFolderId: 'note-folder',
                attachment: 'note-attachment',
                quickCaptureText: 'quick-capture-text',
                folderName: 'folder-name',
                reminderAtUtc: 'reminder-at',
            };

            if (fields[field]) {
                document.getElementById(fields[field])?.focus();
            }
        }
    }"
    x-on:keydown.window="handleShortcut($event)"
    x-on:online.window="connectionStatus = @js(__('Online'))"
    x-on:offline.window="connectionStatus = @js(__('Offline'))"
    x-on:note-selected.window="mobileView = 'editor'"
    x-on:quick-capture-opened.window="$nextTick(() => $refs.quickCaptureInput?.focus())"
    x-on:quick-capture-closed.window="returnFromQuickCapture()"
    x-on:focus-target.window="focusTarget($event.detail.id)"
    x-on:note-saved.window="$nextTick(() => document.querySelector('#note-editor .ProseMirror')?.focus())"
    x-on:validation-failed.window="focusValidationField($event.detail.field)"
>
    <h1 class="sr-only">{{ $isTrashView ? __('Trash') : __('Notes') }}</h1>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <p role="status" aria-live="polite" aria-atomic="true"
            class="inline-flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
            <span class="h-2 w-2 rounded-full"
                :class="connectionStatus === @js(__('Online')) ? 'bg-emerald-600' : 'bg-amber-600'"
                aria-hidden="true"></span>
            <span x-text="connectionStatus"></span>
        </p>

        <span wire:loading wire:target="saveNote" aria-hidden="true" class="text-sm text-zinc-500">
            {{ __('Saving…') }}
        </span>
    </div>

    <div class="grid grid-cols-1 gap-6 md:grid-cols-4">

        {{-- Folders --}}
        <aside class="space-y-2 lg:col-span-1" aria-labelledby="folders-heading">
            <div class="flex items-center justify-between gap-2">
                <h2 id="folders-heading" class="text-lg font-semibold">
                    {{ __('Folders') }}
                </h2>

                <flux:button type="button" variant="ghost" size="sm" icon="plus" class="min-h-11 min-w-11"
                    wire:click="openCreateFolderModal">
                    {{ __('New folder') }}
                </flux:button>
            </div>

            <button wire:click="showNotes; selectFolder(null)"
                @if ($selectedFolderId === null && !$isTrashView) aria-current="page" @endif
                class="block min-h-11 w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800 {{ $selectedFolderId === null && !$isTrashView ? 'bg-zinc-100 dark:bg-zinc-800' : '' }}">
                All Notes
            </button>

            @foreach ($folders as $folder)
                <div wire:key="folder-{{ $folder->id }}" class="flex items-center gap-1">
                    <button type="button" wire:click="showNotes; selectFolder({{ $folder->id }})"
                        @if ($selectedFolderId === $folder->id && !$isTrashView) aria-current="page" @endif
                        class="block min-h-11 min-w-0 flex-1 truncate rounded-lg px-3 py-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800 {{ $selectedFolderId === $folder->id && !$isTrashView ? 'bg-zinc-100 dark:bg-zinc-800' : '' }}">
                        {{ $folder->name }}
                    </button>

                    <flux:dropdown position="bottom" align="end">
                        <flux:button type="button" variant="ghost" size="sm" icon="ellipsis-horizontal" class="min-h-11 min-w-11"
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
                class="block min-h-11 w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800">
                Trash
            </button>
        </aside>

        {{-- Notes --}}
        <section class="min-w-0 md:col-span-3 lg:grid lg:grid-cols-[minmax(16rem,22rem)_minmax(0,1fr)] lg:items-start lg:gap-6" aria-labelledby="note-list-heading">
            <div class="min-w-0" data-note-list :class="mobileView === 'editor' ? 'max-lg:hidden' : ''">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <h2 id="note-list-heading" x-ref="noteListHeading" tabindex="-1" class="text-2xl font-bold focus:outline-none">
                    {{ $isTrashView ? __('Trash') : __('Notes') }}
                </h2>

                @if (!$isTrashView)
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('pwa.notes') }}"
                            class="inline-flex min-h-11 items-center rounded-lg border px-4 py-2 text-sm font-medium">
                            {{ __('Install app') }}
                        </a>

                        <flux:tooltip content="{{ __('Quick capture (Alt+Shift+N)') }}" class="hidden md:inline-flex">
                            <button type="button" id="quick-capture-button" x-ref="quickCaptureButton"
                                x-on:click="openQuickCapture()"
                                aria-keyshortcuts="Alt+Shift+N"
                                class="min-h-11 rounded-lg border px-4 py-2 text-sm font-medium">
                                {{ __('Quick capture') }}
                            </button>
                        </flux:tooltip>

                        <button type="button" wire:click="createNote"
                            class="min-h-11 rounded-lg bg-black px-4 py-2 text-sm font-medium text-white">
                            {{ __('New note') }}
                        </button>
                    </div>
                @endif
            </div>

            <div class="mb-6 grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem]">
                <div>
                    <label for="notes-search" class="mb-1 block text-sm font-medium">{{ __('Search notes') }}</label>
                    <input id="notes-search" type="search" wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search notes...') }}"
                        class="min-h-11 w-full rounded-lg border px-4 py-2">
                </div>

                <div>
                    <label for="notes-sort" class="mb-1 block text-sm font-medium">{{ __('Sort by') }}</label>
                    <select id="notes-sort" wire:model.live="sortBy" class="min-h-11 w-full rounded-lg border px-4 py-2">
                        <option value="newest">{{ __('Newest') }}</option>
                        <option value="oldest">{{ __('Oldest') }}</option>
                        <option value="title_asc">{{ __('Title A-Z') }}</option>
                        <option value="title_desc">{{ __('Title Z-A') }}</option>
                    </select>
                </div>
            </div>

            @if ($unavailableNote)
                <p class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100" role="status">
                    {{ __('This note is no longer available') }}
                </p>
            @endif

            @if ($notes->isEmpty())
                <p class="text-zinc-500">
                    {{ __('No notes yet.') }}
                </p>
            @else
                <div class="space-y-3">
                    @foreach ($notes as $note)
                        <article wire:key="note-row-{{ $note->id }}" class="rounded-lg border p-4">
                            @if (!$isTrashView)
                                <button type="button" id="note-row-{{ $note->id }}"
                                    wire:click="selectNote({{ $note->id }})"
                                    @if ($selectedNoteId === $note->id) aria-current="true" @endif
                                    class="block w-full rounded text-left focus-visible:outline-3 focus-visible:outline-offset-4">
                                    <span class="block font-semibold">{{ $note->title }}</span>

                                    <span class="mt-1 block text-sm text-zinc-500">
                                        {{ \Illuminate\Support\Str::limit(html_entity_decode(strip_tags($note->content ?? '')), 120) }}
                                    </span>
                                </button>
                            @else
                                <div id="note-row-{{ $note->id }}">
                                    <span class="block font-semibold">{{ $note->title }}</span>

                                    <p class="mt-1 text-sm text-zinc-500">
                                        {{ \Illuminate\Support\Str::limit(html_entity_decode(strip_tags($note->content ?? '')), 120) }}
                                    </p>
                                </div>
                            @endif
                            @if ($note->reminder_at !== null)
                                <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                                    <span>{{ __('Reminder') }}</span>
                                    <time
                                        datetime="{{ $note->reminder_at->utc()->toIso8601String() }}"
                                        data-reminder-at="{{ $note->reminder_at->utc()->toIso8601String() }}"
                                        x-data
                                        x-text="window.noteReminderTime.formatReminderTime($el.dataset.reminderAt)"
                                    >{{ $note->reminder_at->utc()->format('Y-m-d H:i') }} UTC</time>
                                    <span class="rounded-full border px-2 py-1 text-xs">{{ __(ucfirst($note->reminder_status)) }}</span>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    @if ($isTrashView)
                                        <button
                                            type="button"
                                            wire:click.stop="clearReminder({{ $note->id }})"
                                            class="min-h-11 rounded-md px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                        >{{ __('Clear reminder') }}</button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click.stop="openReminderModal({{ $note->id }})"
                                            class="min-h-11 rounded-md px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                        >{{ __('Change') }}</button>
                                        @if ($note->reminder_status === 'failed')
                                            <button
                                                type="button"
                                                wire:click.stop="retryReminder({{ $note->id }})"
                                                class="min-h-11 rounded-md px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                            >{{ __('Retry now') }}</button>
                                        @endif
                                        <button
                                            type="button"
                                            wire:click.stop="clearReminder({{ $note->id }})"
                                            class="min-h-11 rounded-md px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                        >{{ __('Clear') }}</button>
                                    @endif
                                </div>
                            @elseif (!$isTrashView)
                                <button
                                    type="button"
                                    wire:click.stop="openReminderModal({{ $note->id }})"
                                    class="mt-2 min-h-11 rounded-md px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                >{{ __('Set reminder') }}</button>
                            @endif

                            @if ($isTrashView)
                                <button type="button" id="restore-note-{{ $note->id }}"
                                    wire:click="restoreNote({{ $note->id }})"
                                    class="mt-3 min-h-11 rounded-lg border px-3 py-2 text-sm font-medium">
                                    {{ __('Restore') }}
                                </button>
                                <button type="button" id="delete-note-{{ $note->id }}"
                                    wire:click="forceDeleteNote({{ $note->id }})"
                                    wire:confirm="{{ __('Permanently delete this note? This cannot be undone.') }}"
                                    class="mt-3 min-h-11 rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white">
                                    {{ __('Delete permanently') }}
                                </button>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
            </div>

            @if ($selectedNote)
                <div class="mt-8 min-w-0 rounded-lg border p-4 sm:p-6 lg:mt-0" :class="mobileView === 'list' ? 'max-lg:hidden' : ''">
                    <div class="mb-4 flex items-center gap-3">
                        <button type="button" x-on:click="backToList()"
                            class="min-h-11 rounded-lg border px-3 py-2 text-sm font-medium lg:hidden">
                            {{ __('Back to notes') }}
                        </button>

                        <h2 id="note-editor-heading" x-ref="editorHeading" tabindex="-1" class="text-xl font-semibold focus:outline-none">
                            {{ __('Edit note') }}
                        </h2>
                    </div>

                    <label for="note-title" class="mb-1 block text-sm font-medium">{{ __('Title') }}</label>
                    <input id="note-title" type="text" wire:model="title"
                        aria-describedby="note-title-error" aria-invalid="{{ $errors->has('title') ? 'true' : 'false' }}"
                        class="mb-1 min-h-11 w-full rounded-lg border px-3 py-2">

                    @error('title')
                        <p id="note-title-error" role="alert" class="mb-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    <label for="note-folder" class="mb-1 mt-3 block text-sm font-medium">{{ __('Folder') }}</label>
                    <select id="note-folder" wire:model="noteFolderId"
                        aria-describedby="note-folder-error" aria-invalid="{{ $errors->has('noteFolderId') ? 'true' : 'false' }}"
                        class="mb-1 min-h-11 w-full rounded-lg border px-3 py-2">
                        <option value="">{{ __('No folder') }}</option>

                        @foreach ($folders as $folder)
                            <option value="{{ $folder->id }}">
                                {{ $folder->name }}
                            </option>
                        @endforeach
                    </select>

                    @error('noteFolderId')
                        <p id="note-folder-error" role="alert" class="mb-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    <div wire:key="tiptap-editor-{{ $selectedNoteId }}" x-data="createTiptapComponent(@js($content), { label: @js(__('Note content')) })" wire:ignore @save-note-requested.window="$wire.saveNote(getContent())">
                        {{-- Toolbar --}}
                        <p id="note-toolbar-help" class="mb-1 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('Formatting toolbar. Use Tab to move between controls; pressed buttons are active formats.') }}
                        </p>
                        <div class="mb-2 rounded-lg border p-1" role="toolbar" aria-label="{{ __('Formatting') }}"
                            aria-describedby="note-toolbar-help">
                            <div class="flex flex-wrap items-center gap-1">
                                <x-notes.toolbar-button label="Bold" action="toggleBold()" :pressed="'activeFormats.bold'" glyph="B" class="font-bold" />
                                <x-notes.toolbar-button label="Italic" action="toggleItalic()" :pressed="'activeFormats.italic'" glyph="I" class="italic" />
                                <x-notes.toolbar-button label="Underline" action="toggleUnderline()" :pressed="'activeFormats.underline'" glyph="U" class="underline" />
                                <x-notes.toolbar-button label="Strikethrough" action="toggleStrike()" :pressed="'activeFormats.strike'" glyph="S" class="line-through" />

                                <x-notes.toolbar-button label="Code block" action="toggleCodeBlock()" :pressed="'activeFormats.codeBlock'" glyph="Code" class="max-md:hidden" />
                                <x-notes.toolbar-button label="Bullet list" action="toggleBulletList()" :pressed="'activeFormats.bulletList'" glyph="Bullets" class="max-md:hidden" />
                                <x-notes.toolbar-button label="Numbered list" action="toggleOrderedList()" :pressed="'activeFormats.orderedList'" glyph="Numbers" class="max-md:hidden" />
                                <x-notes.toolbar-button label="Task list" action="toggleTaskList()" :pressed="'activeFormats.taskList'" glyph="Tasks" class="max-md:hidden" />

                                <x-notes.toolbar-button label="Paragraph" action="setParagraph()" :pressed="'activeFormats.paragraph'" glyph="P" class="max-md:hidden" />
                                <x-notes.toolbar-button label="Heading 1" action="setHeading(1)" :pressed="'activeFormats.heading1'" glyph="H1" class="max-md:hidden font-bold" />
                                <x-notes.toolbar-button label="Heading 2" action="setHeading(2)" :pressed="'activeFormats.heading2'" glyph="H2" class="max-md:hidden font-bold" />
                                <x-notes.toolbar-button label="Heading 3" action="setHeading(3)" :pressed="'activeFormats.heading3'" glyph="H3" class="max-md:hidden font-bold" />

                                <select aria-label="{{ __('Font size') }}" :value="activeFormats.fontSize" @change="setFontSize($event.target.value)"
                                    class="max-md:hidden min-h-6 rounded border border-zinc-300 bg-white px-2 py-1 text-sm">
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

                                <input type="color" aria-label="{{ __('Font color') }}" :value="activeFormats.color" @input="setColor($event.target.value)"
                                    class="max-md:hidden h-6 w-8 cursor-pointer rounded border border-zinc-300 bg-white p-0.5" />

                                <x-notes.toolbar-button label="Highlight" action="toggleHighlight()" :pressed="'activeFormats.highlight'" glyph="Mark" class="max-md:hidden" />
                                <x-notes.toolbar-button label="Link" action="setLink()" :pressed="'activeFormats.link'" glyph="Link" class="max-md:hidden" />

                                <x-notes.toolbar-button label="Align left" action="setTextAlign('left')" :pressed="'activeFormats.alignLeft'" glyph="Left" class="max-md:hidden" />
                                <x-notes.toolbar-button label="Align center" action="setTextAlign('center')" :pressed="'activeFormats.alignCenter'" glyph="Center" class="max-md:hidden" />
                                <x-notes.toolbar-button label="Align right" action="setTextAlign('right')" :pressed="'activeFormats.alignRight'" glyph="Right" class="max-md:hidden" />
                                <x-notes.toolbar-button label="Justify" action="setTextAlign('justify')" :pressed="'activeFormats.alignJustify'" glyph="Justify" class="max-md:hidden" />

                                <x-notes.toolbar-button label="Insert table" action="insertTable()" glyph="Table" class="max-md:hidden" />

                                <div x-show="activeFormats.table" x-data="{ tableMenuOpen: false }" class="relative max-md:hidden">
                                    <button type="button" @mousedown.prevent @click="tableMenuOpen = ! tableMenuOpen"
                                        :aria-expanded="tableMenuOpen ? 'true' : 'false'" aria-haspopup="true"
                                        aria-label="{{ __('Table options') }}"
                                        class="inline-flex min-h-6 items-center rounded px-3 py-1 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                        {{ __('Table') }} ▾
                                    </button>

                                    <div x-show="tableMenuOpen" @click.outside="tableMenuOpen = false" @keydown.escape="tableMenuOpen = false"
                                        role="group" aria-label="{{ __('Table options') }}"
                                        class="absolute left-0 top-full z-50 mt-1 w-48 rounded-lg border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                                        <button type="button" @mousedown.prevent @click="addTableRowBefore(); tableMenuOpen = false"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                            {{ __('Add row above') }}
                                        </button>
                                        <button type="button" @mousedown.prevent @click="addTableRowAfter(); tableMenuOpen = false"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                            {{ __('Add row below') }}
                                        </button>
                                        <button type="button" @mousedown.prevent @click="deleteTableRow(); tableMenuOpen = false"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                            {{ __('Delete row') }}
                                        </button>
                                        <button type="button" @mousedown.prevent @click="addTableColumnBefore(); tableMenuOpen = false"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                            {{ __('Add column left') }}
                                        </button>
                                        <button type="button" @mousedown.prevent @click="addTableColumnAfter(); tableMenuOpen = false"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                            {{ __('Add column right') }}
                                        </button>
                                        <button type="button" @mousedown.prevent @click="deleteTableColumn(); tableMenuOpen = false"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                            {{ __('Delete column') }}
                                        </button>
                                        <button type="button" @mousedown.prevent @click="deleteTable(); tableMenuOpen = false"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950">
                                            {{ __('Delete table') }}
                                        </button>
                                    </div>
                                </div>

                                <div x-show="activeFormats.table" x-data="{ widthMenuOpen: false }" class="relative max-md:hidden">
                                    <button type="button" @mousedown.prevent @click="widthMenuOpen = ! widthMenuOpen"
                                        :aria-expanded="widthMenuOpen ? 'true' : 'false'" aria-haspopup="true"
                                        aria-label="{{ __('Table width') }}"
                                        class="inline-flex min-h-6 items-center rounded px-3 py-1 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                        {{ __('Width') }} ▾
                                    </button>

                                    <div x-show="widthMenuOpen" @click.outside="widthMenuOpen = false" @keydown.escape="widthMenuOpen = false"
                                        role="group" aria-label="{{ __('Table width') }}"
                                        class="absolute left-0 top-full z-50 mt-1 w-48 rounded-lg border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                                        <button type="button" @mousedown.prevent @click="setTableWidth(50); widthMenuOpen = false"
                                            :aria-pressed="activeFormats.tableWidth === 'narrow' ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                            {{ __('Narrow (50%)') }}
                                        </button>
                                        <button type="button" @mousedown.prevent @click="setTableWidth(75); widthMenuOpen = false"
                                            :aria-pressed="activeFormats.tableWidth === 'medium' ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                            {{ __('Medium (75%)') }}
                                        </button>
                                        <button type="button" @mousedown.prevent @click="setTableWidth(100); widthMenuOpen = false"
                                            :aria-pressed="activeFormats.tableWidth === 'wide' ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                            {{ __('Wide (100%)') }}
                                        </button>
                                        <button type="button" @mousedown.prevent @click="setTableWidth(null); widthMenuOpen = false"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                            {{ __('Reset width') }}
                                        </button>
                                    </div>
                                </div>

                                <div class="relative md:hidden" x-data="{ moreMenuOpen: false }">
                                    <button type="button" @mousedown.prevent @click="moreMenuOpen = ! moreMenuOpen"
                                        :aria-expanded="moreMenuOpen ? 'true' : 'false'" aria-haspopup="true"
                                        aria-label="{{ __('More formatting') }}"
                                        class="inline-flex min-h-6 items-center rounded px-3 py-1 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                        {{ __('More') }}
                                    </button>

                                    <div x-show="moreMenuOpen" @click.outside="moreMenuOpen = false" @keydown.escape="moreMenuOpen = false"
                                        role="group" aria-label="{{ __('More formatting') }}"
                                        class="absolute right-0 top-full z-50 mt-1 max-h-80 w-56 overflow-y-auto rounded-lg border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                                        <button type="button" @mousedown.prevent @click="toggleBulletList()"
                                            :aria-pressed="activeFormats.bulletList ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Bullet list') }}</button>
                                        <button type="button" @mousedown.prevent @click="toggleOrderedList()"
                                            :aria-pressed="activeFormats.orderedList ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Numbered list') }}</button>
                                        <button type="button" @mousedown.prevent @click="toggleTaskList()"
                                            :aria-pressed="activeFormats.taskList ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Task list') }}</button>
                                        <button type="button" @mousedown.prevent @click="toggleCodeBlock()"
                                            :aria-pressed="activeFormats.codeBlock ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Code block') }}</button>
                                        <button type="button" @mousedown.prevent @click="setParagraph()"
                                            :aria-pressed="activeFormats.paragraph ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Paragraph') }}</button>
                                        <button type="button" @mousedown.prevent @click="setHeading(1)"
                                            :aria-pressed="activeFormats.heading1 ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm font-bold hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Heading 1') }}</button>
                                        <button type="button" @mousedown.prevent @click="setHeading(2)"
                                            :aria-pressed="activeFormats.heading2 ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm font-bold hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Heading 2') }}</button>
                                        <button type="button" @mousedown.prevent @click="setHeading(3)"
                                            :aria-pressed="activeFormats.heading3 ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm font-bold hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Heading 3') }}</button>

                                        <label for="more-font-size" class="mt-1 block px-3 py-2 text-xs font-medium text-zinc-500">{{ __('Font size') }}</label>
                                        <select id="more-font-size" :value="activeFormats.fontSize" @change="setFontSize($event.target.value)"
                                            class="mx-3 mb-2 min-h-11 rounded border border-zinc-300 bg-white px-2 py-1 text-sm">
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

                                        <label for="more-font-color" class="block px-3 py-2 text-xs font-medium text-zinc-500">{{ __('Font color') }}</label>
                                        <input id="more-font-color" type="color" :value="activeFormats.color" @input="setColor($event.target.value)"
                                            class="mx-3 mb-2 h-11 w-16 cursor-pointer rounded border border-zinc-300 bg-white p-1" />

                                        <button type="button" @mousedown.prevent @click="toggleHighlight()"
                                            :aria-pressed="activeFormats.highlight ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Highlight') }}</button>
                                        <button type="button" @mousedown.prevent @click="setLink()"
                                            :aria-pressed="activeFormats.link ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Link') }}</button>
                                        <button type="button" @mousedown.prevent @click="setTextAlign('left')"
                                            :aria-pressed="activeFormats.alignLeft ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Align left') }}</button>
                                        <button type="button" @mousedown.prevent @click="setTextAlign('center')"
                                            :aria-pressed="activeFormats.alignCenter ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Align center') }}</button>
                                        <button type="button" @mousedown.prevent @click="setTextAlign('right')"
                                            :aria-pressed="activeFormats.alignRight ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Align right') }}</button>
                                        <button type="button" @mousedown.prevent @click="setTextAlign('justify')"
                                            :aria-pressed="activeFormats.alignJustify ? 'true' : 'false'"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Justify') }}</button>
                                        <button type="button" @mousedown.prevent @click="insertTable(); moreMenuOpen = false"
                                            class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Insert table') }}</button>

                                        <div x-show="activeFormats.table">
                                            <p class="mt-1 px-3 py-2 text-xs font-medium text-zinc-500">{{ __('Table options') }}</p>
                                            <button type="button" @mousedown.prevent @click="addTableRowBefore(); moreMenuOpen = false"
                                                class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Add row above') }}</button>
                                            <button type="button" @mousedown.prevent @click="addTableRowAfter(); moreMenuOpen = false"
                                                class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Add row below') }}</button>
                                            <button type="button" @mousedown.prevent @click="deleteTableRow(); moreMenuOpen = false"
                                                class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Delete row') }}</button>
                                            <button type="button" @mousedown.prevent @click="addTableColumnBefore(); moreMenuOpen = false"
                                                class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Add column left') }}</button>
                                            <button type="button" @mousedown.prevent @click="addTableColumnAfter(); moreMenuOpen = false"
                                                class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Add column right') }}</button>
                                            <button type="button" @mousedown.prevent @click="deleteTableColumn(); moreMenuOpen = false"
                                                class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Delete column') }}</button>
                                            <button type="button" @mousedown.prevent @click="deleteTable(); moreMenuOpen = false"
                                                class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950">{{ __('Delete table') }}</button>

                                            <p class="mt-1 px-3 py-2 text-xs font-medium text-zinc-500">{{ __('Table width') }}</p>
                                            <button type="button" @mousedown.prevent @click="setTableWidth(50); moreMenuOpen = false"
                                                :aria-pressed="activeFormats.tableWidth === 'narrow' ? 'true' : 'false'"
                                                class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Narrow (50%)') }}</button>
                                            <button type="button" @mousedown.prevent @click="setTableWidth(75); moreMenuOpen = false"
                                                :aria-pressed="activeFormats.tableWidth === 'medium' ? 'true' : 'false'"
                                                class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Medium (75%)') }}</button>
                                            <button type="button" @mousedown.prevent @click="setTableWidth(100); moreMenuOpen = false"
                                                :aria-pressed="activeFormats.tableWidth === 'wide' ? 'true' : 'false'"
                                                class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Wide (100%)') }}</button>
                                            <button type="button" @mousedown.prevent @click="setTableWidth(null); moreMenuOpen = false"
                                                class="block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('Reset width') }}</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Editor --}}
                        <div x-ref="editor" class="w-full rounded-lg border"></div>
                    </div>

                    <div class="mt-6 border-t border-zinc-200 pt-4">
                        <h3 class="mb-3 text-sm font-semibold text-zinc-800 dark:text-zinc-100">
                            {{ __('Attachments') }}
                        </h3>

                        <div class="mb-4 overflow-hidden rounded-lg border border-zinc-200">
                            @forelse ($selectedNote->attachments as $noteAttachment)
                                <div wire:key="attachment-{{ $noteAttachment->id }}" class="flex flex-wrap items-center justify-between gap-3 p-3 {{ !$loop->last ? 'border-b border-zinc-200' : '' }}">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium">{{ $noteAttachment->original_name }}</p>
                                        <p class="text-xs text-zinc-500">{{ number_format($noteAttachment->file_size / 1024, 1) }} KB</p>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2">
                                        <a href="{{ route('note-attachments.download', $noteAttachment) }}"
                                            aria-label="{{ __('Download :name', ['name' => $noteAttachment->original_name]) }}"
                                            class="inline-flex min-h-11 items-center rounded-lg border px-3 py-1 text-sm font-medium">
                                            {{ __('Download') }}
                                        </a>
                                        <button type="button" wire:click="deleteAttachment({{ $noteAttachment->id }})"
                                            wire:confirm="{{ __('Delete this attachment?') }}"
                                            aria-label="{{ __('Delete :name', ['name' => $noteAttachment->original_name]) }}"
                                            class="min-h-11 rounded-lg border border-red-200 px-3 py-1 text-sm font-medium text-red-600">
                                            {{ __('Delete') }}
                                        </button>
                                    </div>
                                </div>
                            @empty
                                <p class="p-3 text-sm text-zinc-500">{{ __('No attachments yet.') }}</p>
                            @endforelse
                        </div>

                        <form wire:submit="uploadAttachment" class="flex flex-wrap items-end gap-3">
                            <div class="min-w-0 flex-1">
                                <label for="note-attachment" class="mb-1 block text-sm font-medium">{{ __('Attachment') }}</label>
                                <input id="note-attachment" type="file" wire:model="attachment"
                                    x-on:cancel="$wire.removeUpload('attachment', @js($attachment?->getFilename()))"
                                    accept=".pdf,.doc,.docx,.txt,.rtf,.odt,.xls,.xlsx,.ppt,.pptx,.csv,.jpg,.jpeg,.png"
                                    aria-describedby="attachment-help attachment-error"
                                    aria-invalid="{{ $errors->has('attachment') ? 'true' : 'false' }}"
                                    class="block min-h-11 max-w-full text-sm text-zinc-600 dark:text-zinc-300" />
                                <p id="attachment-help" class="mt-1 text-xs text-zinc-500">
                                    {{ __('PDF, Word, text, spreadsheet, presentation, or image up to 10 MB.') }}
                                </p>
                            </div>

                            <button type="submit" wire:loading.attr="disabled"
                                wire:target="uploadAttachment, attachment"
                                class="min-h-11 rounded-lg bg-black px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                                {{ __('Upload file') }}
                            </button>
                        </form>

                        <p wire:loading wire:target="attachment" aria-hidden="true" class="mt-2 text-sm text-zinc-500">
                            {{ __('Uploading file to the server...') }}
                        </p>

                        @error('attachment')
                            <p id="attachment-error" wire:key="attachment-validation-{{ $attachmentValidationAttempt }}"
                                role="alert"
                                class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <button type="button" @click="$dispatch('save-note-requested')"
                            class="min-h-11 rounded-lg bg-black px-4 py-2 text-sm font-medium text-white">
                            {{ __('Save') }}
                        </button>

                        <button type="button" wire:click="deleteNote" wire:confirm="{{ __('Move this to Trash?') }}"
                            class="min-h-11 rounded-lg border px-4 py-2 text-sm font-medium">
                            {{ __('Delete') }}
                        </button>
                    </div>
                </div>
            @endif
        </section>

    </div>

    @if (!$isTrashView)
        <button type="button" id="quick-capture-floating" x-ref="quickCaptureFloating"
            x-on:click="openQuickCapture()"
            class="fixed bottom-[calc(1.5rem_+_env(safe-area-inset-bottom))] right-[calc(1.5rem_+_env(safe-area-inset-right))] z-40 inline-flex h-14 w-14 items-center justify-center rounded-full bg-black text-white shadow-lg md:hidden"
            aria-label="{{ __('Quick capture') }}" aria-keyshortcuts="Alt+Shift+N">
            <flux:icon.plus class="size-6" />
        </button>
    @endif

    <flux:modal name="manage-folder" wire:model="showFolderModal" class="min-w-0 w-full max-w-md">
        <form wire:submit="saveFolder" class="space-y-6" x-init="$el.closest('dialog')?.setAttribute('aria-labelledby', 'folder-modal-heading')">
            <div>
                <flux:heading id="folder-modal-heading" level="2" size="lg">
                    {{ $editingFolderId === null ? __('Create a folder') : __('Rename folder') }}
                </flux:heading>
                <flux:subheading>
                    {{ __('Folder names are unique within your account.') }}
                </flux:subheading>
            </div>

            <flux:field>
                <flux:label for="folder-name">{{ __('Folder name') }}</flux:label>
                <flux:input id="folder-name" wire:model="folderName" autofocus
                    aria-describedby="folder-name-error"
                    aria-invalid="{{ $errors->has('folderName') ? 'true' : 'false' }}" />
                <flux:error id="folder-name-error" name="folderName" role="alert" />
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

    <flux:modal name="note-reminder" wire:model="showReminderModal" class="min-w-0 w-full max-w-md">
        <div class="space-y-6" x-init="$el.closest('dialog')?.setAttribute('aria-labelledby', 'reminder-modal-heading')">
            <div>
                <flux:heading id="reminder-modal-heading" level="2" size="lg">{{ __('Note reminder') }}</flux:heading>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                    {{ __('Choose when you would like an email reminder. The time uses your browser’s current time zone.') }}
                </p>
            </div>

            @if ($reminderNoteId !== null)
                <form
                    wire:key="note-reminder-form-{{ $reminderFormKey }}"
                    x-data="{
                        localError: '',
                        submitReminder(noteId, localValue) {
                            this.localError = '';
                            const utcValue = window.noteReminderTime.localDateTimeToUtc(localValue);

                            if (!utcValue) {
                                this.localError = @js(__('That local time does not exist. Choose another time.'));
                                this.$refs.reminderTime?.focus();

                                return;
                            }

                            $wire.saveReminder(noteId, utcValue);
                        },
                    }"
                    x-on:submit.prevent="submitReminder(@js($reminderNoteId), $refs.reminderTime.value)"
                >
                    <flux:field>
                        <flux:label for="reminder-at">{{ __('Date and time') }}</flux:label>
                        <flux:input
                            id="reminder-at"
                            type="datetime-local"
                            step="60"
                            required
                            x-ref="reminderTime"
                            x-init="$el.value = window.noteReminderTime.utcToLocalDateTime(@js($reminderAtUtc))"
                            aria-describedby="reminder-server-error reminder-local-error"
                            aria-invalid="{{ $errors->has('reminderAtUtc') ? 'true' : 'false' }}"
                        />
                        <p id="reminder-server-error" class="text-sm text-red-600 dark:text-red-400" role="alert">
                            @error('reminderAtUtc'){{ $message }}@enderror
                        </p>
                        <p
                            id="reminder-local-error"
                            class="text-sm text-red-600 dark:text-red-400"
                            role="alert"
                            x-show="localError"
                            x-text="localError"
                        ></p>
                    </flux:field>

                    <div class="flex items-center justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                            <span wire:loading.remove>{{ __('Save reminder') }}</span>
                            <span wire:loading>{{ __('Saving') }}</span>
                        </flux:button>
                    </div>
                </form>
            @endif
        </div>
    </flux:modal>

    <flux:modal name="quick-capture" wire:model="showQuickCaptureModal" wire:close="closeQuickCapture"
        class="min-w-0 w-full max-w-md">
        <form x-on:submit.prevent="submitQuickCapture()" class="space-y-6" x-init="$el.closest('dialog')?.setAttribute('aria-labelledby', 'quick-capture-heading')">
            <div>
                <flux:heading id="quick-capture-heading" level="2" size="lg">
                    {{ __('Capture a thought') }}
                </flux:heading>
                <flux:subheading>
                    {{ __('The first line becomes the title.') }}
                </flux:subheading>
            </div>

            <flux:field>
                <flux:label for="quick-capture-text">{{ __('Note') }}</flux:label>
                <flux:textarea id="quick-capture-text" wire:model="quickCaptureText" x-ref="quickCaptureInput"
                    x-on:input="quickCaptureCanSave = $event.target.value.trim() !== ''"
                    x-on:keydown.ctrl.enter.prevent="submitQuickCapture()"
                    x-on:keydown.meta.enter.prevent="submitQuickCapture()" autofocus rows="5"
                    aria-describedby="quick-capture-error"
                    aria-invalid="{{ $errors->has('quickCaptureText') ? 'true' : 'false' }}"
                    placeholder="{{ __('What is on your mind?') }}" />
                <p x-show="!quickCaptureCanSave" class="text-sm text-zinc-500">
                    {{ __('Write something to capture.') }}
                </p>
                <flux:error id="quick-capture-error" name="quickCaptureText" role="alert" />
            </flux:field>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-zinc-500">
                    <kbd class="rounded border px-1">Ctrl</kbd>/<kbd class="rounded border px-1">Cmd</kbd> +
                    <kbd class="rounded border px-1">Enter</kbd> {{ __('to save') }}
                    <span class="mx-2">·</span>
                    <kbd class="rounded border px-1">Alt</kbd> + <kbd class="rounded border px-1">Shift</kbd> +
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
