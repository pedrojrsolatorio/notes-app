<?php

use App\Jobs\SendNoteReminder;
use App\Models\Folder;
use App\Models\Note;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        $this->loadFirstNotePage();

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

    #[Locked]
    public array $noteRows = [];

    public ?string $notesCursor = null;

    #[Locked]
    public bool $hasMoreNotes = false;

    #[Locked]
    public ?int $matchedNotesCount = null;

    #[Locked]
    public ?int $searchCount = null;

    #[Locked]
    public string $listStatusMessage = '';

    #[Locked]
    public int $listMessageId = 0;

    /** @var array{id: int, title: string, content: string, folder_id: int|null}|null */
    #[Locked]
    public ?array $suspendedNoteDraft = null;

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
        $this->suspendedNoteDraft = null;
        $changed = $this->selectedFolderId !== $folderId || $this->isTrashView;
        $this->selectedFolderId = $folderId;
        $this->isTrashView = false;

        if ($changed) {
            $this->resetNoteList(focus: true);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetNoteList(focus: false);
    }

    public function updatedSortBy(): void
    {
        $this->resetNoteList(focus: true);
    }

    public function updatedSelectedFolderId(): void
    {
        $this->resetNoteList(focus: true);
    }

    public function updatedIsTrashView(): void
    {
        $this->resetNoteList(focus: true);
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
        $this->resetNoteList(focus: false);

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
        $this->suspendedNoteDraft = null;

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

        $this->resetNoteList(focus: false);
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

        $this->resetNoteList(focus: false);
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
        $this->resetNoteList(focus: false);
        $this->restoreSuspendedNoteDraft();
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

        $ids = array_column($this->noteRows, 'id');
        $index = array_search($note->id, $ids, true);
        $nextId = $index === false ? null : ($ids[$index + 1] ?? $ids[$index - 1] ?? null);

        $note->delete();

        $this->selectedNoteId = null;
        $this->title = '';
        $this->content = '';
        $this->noteFolderId = null;

        $this->resetNoteList(focus: false);
        $this->dispatch(
            'focus-target',
            id: $nextId !== null && collect($this->noteRows)->contains('id', $nextId)
                ? 'note-row-'.$nextId
                : 'note-list-heading'
        );

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

        $this->resetNoteList(focus: false);
        $this->dispatch('focus-target', id: 'note-list-heading');

        Flux::toast(variant: 'success', text: __('Note restored.'));
    }

    public function forceDeleteNote(int $noteId): void
    {
        $note = auth()->user()->notes()->onlyTrashed()->findOrFail($noteId);

        $ids = array_column($this->noteRows, 'id');
        $index = array_search($note->id, $ids, true);
        $nextId = $index === false ? null : ($ids[$index + 1] ?? $ids[$index - 1] ?? null);

        $note->forceDelete();

        $this->resetNoteList(focus: false);
        $this->dispatch(
            'focus-target',
            id: $nextId !== null && collect($this->noteRows)->contains('id', $nextId)
                ? 'restore-note-'.$nextId
                : 'note-list-heading'
        );

        Flux::toast(variant: 'success', text: __('Note permanently deleted.'));
    }

    public function showTrash(): void
    {
        $this->suspendedNoteDraft = null;
        $this->isTrashView = true;
        $this->selectedFolderId = null;
        $this->selectedNoteId = null;
        $this->title = '';
        $this->content = '';
        $this->noteFolderId = null;
        $this->showQuickCaptureModal = false;
        $this->quickCaptureText = '';
        $this->resetValidation('quickCaptureText');
        $this->resetNoteList(focus: false);
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

        return $notesQuery;
    }

    private function normalizedSort(): string
    {
        return in_array($this->sortBy, ['newest', 'oldest', 'title_asc', 'title_desc'], true)
            ? $this->sortBy
            : 'newest';
    }

    /**
     * @return array{search: string, folder_id: int|null, trash: bool}
     */
    private function noteListFilter(): array
    {
        return [
            'search' => trim($this->search),
            'folder_id' => $this->isTrashView ? null : $this->selectedFolderId,
            'trash' => $this->isTrashView,
        ];
    }

    private function loadFirstNotePage(?string $statusMessage = null): void
    {
        $this->notesCursor = null;
        $this->noteRows = [];
        $this->hasMoreNotes = false;
        $this->matchedNotesCount = null;
        $this->searchCount = null;

        try {
            $page = $this->queryNotePage();
            $this->noteRows = $page['rows'];
            $this->notesCursor = $page['cursor'];
            $this->hasMoreNotes = $page['has_more'];
            $this->matchedNotesCount = $page['matched_count'];
            $this->searchCount = trim($this->search) !== ''
                ? ($page['matched_count'] ?? $this->noteListQuery(auth()->user())->count())
                : null;
        } catch (QueryException) {
            $statusMessage = __('Notes could not be loaded. Try again.');
        }

        if ($statusMessage !== null) {
            $this->announceListStatus($statusMessage);
        }
    }

    private function resetNoteList(bool $focus, ?string $statusMessage = null): void
    {
        $user = auth()->user();

        if ($this->selectedNoteId !== null) {
            $selectedNoteMatches = ! $this->isTrashView
                && $this->noteListQuery($user)->whereKey($this->selectedNoteId)->exists();

            if (! $selectedNoteMatches) {
                $this->suspendedNoteDraft ??= [
                    'id' => $this->selectedNoteId,
                    'title' => $this->title,
                    'content' => $this->content,
                    'folder_id' => $this->noteFolderId,
                ];
                $this->selectedNoteId = null;
                $this->title = '';
                $this->content = '';
                $this->noteFolderId = null;
                $focus = true;
            }
        }

        $this->loadFirstNotePage($statusMessage);

        if ($focus) {
            $this->dispatch('focus-target', id: 'note-list-heading');
        }
    }

    private function restoreSuspendedNoteDraft(): void
    {
        $draft = $this->suspendedNoteDraft;
        $this->suspendedNoteDraft = null;

        if ($draft === null || auth()->user()->notes()->find($draft['id']) === null) {
            return;
        }

        $this->selectedNoteId = $draft['id'];
        $this->title = $draft['title'];
        $this->content = $draft['content'];
        $this->noteFolderId = $draft['folder_id'];
    }

    public function loadMoreNotes(): void
    {
        if ($this->notesCursor === null) {
            return;
        }

        $cursor = $this->validatedNotesCursor($this->notesCursor);

        if ($cursor === null) {
            $this->resetNoteList(
                focus: true,
                statusMessage: __('The note list was refreshed because its page was no longer valid.')
            );

            return;
        }

        try {
            $page = $this->queryNotePage($cursor);
        } catch (QueryException) {
            $this->announceListStatus(__('Could not load more notes. Try again.'));

            return;
        }

        $this->noteRows = [...$this->noteRows, ...$page['rows']];
        $this->notesCursor = $page['cursor'];
        $this->hasMoreNotes = $page['has_more'];
        $this->matchedNotesCount = $page['matched_count'];

        $message = __(':count notes loaded.', ['count' => count($page['rows'])]);

        if (! $page['has_more']) {
            $message .= ' '.__(':count notes total.', ['count' => $page['matched_count']]);
            $this->dispatch('focus-target', id: 'note-list-heading');
        } else {
            $this->dispatch('focus-target', id: 'load-more-notes');
        }

        $this->announceListStatus($message);
    }

    private function announceListStatus(string $message): void
    {
        $this->listStatusMessage = $message;
        $this->listMessageId++;
    }

    /**
     * @param  array{v: int, sort: string, filter: array, key: string, anchor: string, anchor_id: int}|null  $cursor
     * @return array{rows: list<array{id: int, portable_id: string, title: string, content: string, reminder_at: string|null, reminder_status: string|null}>, cursor: string|null, has_more: bool, matched_count: int|null}
     */
    private function queryNotePage(?array $cursor = null): array
    {
        $user = auth()->user();
        $sort = $this->normalizedSort();
        $query = $this->noteListQuery($user);

        if ($cursor !== null) {
            $column = in_array($sort, ['newest', 'oldest'], true) ? 'updated_at' : 'title_key';
            $key = $column === 'updated_at'
                ? CarbonImmutable::parse($cursor['key'])->utc()->format('Y-m-d H:i:s')
                : $cursor['key'];
            $comparison = in_array($sort, ['newest', 'title_desc'], true) ? '<' : '>';

            $query->where(function ($query) use ($column, $comparison, $key, $cursor): void {
                $query->where($column, $comparison, $key)
                    ->orWhere(function ($query) use ($column, $comparison, $key, $cursor): void {
                        $query->where($column, $key)
                            ->where('id', $comparison, $cursor['anchor_id']);
                    });
            });
        }

        match ($sort) {
            'oldest' => $query->orderBy('updated_at')->orderBy('id'),
            'title_asc' => $query->orderBy('title_key')->orderBy('id'),
            'title_desc' => $query->orderByDesc('title_key')->orderByDesc('id'),
            default => $query->orderByDesc('updated_at')->orderByDesc('id'),
        };

        $notes = $query
            ->select([
                'notes.id',
                'notes.portable_id',
                'notes.title',
                'notes.title_key',
                'notes.reminder_at',
                'notes.reminder_status',
                'notes.updated_at',
            ])
            ->selectRaw('substr(notes.content, 1, 2000) as content')
            ->limit(51)
            ->get();

        $hasMore = $notes->count() > 50;
        $visibleNotes = $notes->take(50);
        $lastNote = $visibleNotes->last();
        $nextCursor = $hasMore && $lastNote !== null
            ? $this->encodeNotesCursor($lastNote, $sort)
            : null;
        $matchedCount = $hasMore ? null : $this->noteListQuery($user)->count();

        return [
            'rows' => $visibleNotes->map(fn (Note $note): array => [
                'id' => (int) $note->getKey(),
                'portable_id' => $note->portable_id,
                'title' => $note->title,
                'content' => $note->content ?? '',
                'reminder_at' => $note->reminder_at?->utc()->toISOString(),
                'reminder_status' => $note->reminder_status,
                'updated_at' => $note->updated_at->utc()->toISOString(),
            ])->values()->all(),
            'cursor' => $nextCursor,
            'has_more' => $hasMore,
            'matched_count' => $matchedCount,
        ];
    }

    private function encodeNotesCursor(Note $note, string $sort): string
    {
        $key = in_array($sort, ['newest', 'oldest'], true)
            ? $note->updated_at->utc()->format('Y-m-d\TH:i:s.u\Z')
            : $note->title_key;
        $json = json_encode([
            'v' => 1,
            'sort' => $sort,
            'filter' => $this->noteListFilter(),
            'key' => $key,
            'anchor' => $note->portable_id,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{v: int, sort: string, filter: array, key: string, anchor: string, anchor_id: int}|null
     */
    private function validatedNotesCursor(string $value): ?array
    {
        $json = base64_decode(strtr($value, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        try {
            $cursor = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($cursor)
            || ($cursor['v'] ?? null) !== 1
            || ($cursor['sort'] ?? null) !== $this->normalizedSort()
            || ($cursor['filter'] ?? null) !== $this->noteListFilter()
            || ! is_string($cursor['key'] ?? null)
            || ! is_string($cursor['anchor'] ?? null)) {
            return null;
        }

        $user = auth()->user();
        $anchor = $this->noteListQuery($user)
            ->where('portable_id', $cursor['anchor'])
            ->first(['id', 'portable_id', 'updated_at', 'title_key']);

        if ($anchor === null) {
            return null;
        }

        $expectedKey = in_array($cursor['sort'], ['newest', 'oldest'], true)
            ? $anchor->updated_at->utc()->format('Y-m-d\TH:i:s.u\Z')
            : $anchor->title_key;

        if (! is_string($expectedKey) || $cursor['key'] !== $expectedKey) {
            return null;
        }

        return [
            'v' => 1,
            'sort' => $cursor['sort'],
            'filter' => $cursor['filter'],
            'key' => $cursor['key'],
            'anchor' => $cursor['anchor'],
            'anchor_id' => (int) $anchor->getKey(),
        ];
    }

    public function render()
    {
        $user = auth()->user();
        $folders = $user->folders()->orderBy('name_key')->get();
        $notes = collect($this->noteRows)->map(fn (array $row): object => (object) [
            ...$row,
            'reminder_at' => $row['reminder_at'] === null
                ? null
                : CarbonImmutable::parse($row['reminder_at']),
            'updated_at' => CarbonImmutable::parse($row['updated_at']),
        ]);
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
        sidebarCollapsed: localStorage.getItem('notes-sidebar-collapsed') === 'true',
        toggleSidebar() {
            this.sidebarCollapsed = !this.sidebarCollapsed;
            localStorage.setItem('notes-sidebar-collapsed', String(this.sidebarCollapsed));
        },
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

        <span wire:loading wire:target="saveNote" aria-hidden="true" class="text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('Saving…') }}
        </span>
    </div>

    <div class="flex flex-col gap-6 md:flex-row">

        {{-- Folders --}}
        <aside class="min-w-0 shrink-0 space-y-1 border-e border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800"
            :class="sidebarCollapsed ? 'lg:w-16 lg:p-2' : 'md:w-48 lg:w-56'"
            aria-labelledby="folders-heading">
            <div class="hidden justify-end lg:flex"
                :class="sidebarCollapsed && 'lg:justify-center'">
                <flux:button type="button" variant="ghost" size="sm"
                    class="min-h-11 min-w-11"
                    x-on:click="toggleSidebar()"
                    x-bind:aria-label="sidebarCollapsed ? @js(__('Expand sidebar')) : @js(__('Collapse sidebar'))"
                    x-bind:title="sidebarCollapsed ? @js(__('Expand sidebar')) : @js(__('Collapse sidebar'))">
                    <flux:icon.chevron-double-left class="size-5 transition-transform"
                        x-bind:class="sidebarCollapsed && 'rotate-180'" />
                </flux:button>
            </div>

            <button type="button" wire:click="showNotes; selectFolder(null)"
                @if ($selectedFolderId === null && !$isTrashView) aria-current="page" @endif
                aria-label="{{ __('All Notes') }}"
                :title="sidebarCollapsed ? @js(__('All Notes')) : null"
                class="flex min-h-11 w-full items-center gap-2.5 rounded-lg px-3 py-2 text-start hover:bg-zinc-100 dark:hover:bg-zinc-700/60 {{ $selectedFolderId === null && !$isTrashView ? 'bg-zinc-200 dark:bg-zinc-700' : '' }}"
                :class="sidebarCollapsed && 'lg:justify-center lg:px-0'">
                <flux:icon.document-text class="size-5 shrink-0 text-zinc-500 dark:text-zinc-400" aria-hidden="true" />
                <span class="min-w-0 truncate" :class="sidebarCollapsed && 'lg:hidden'">All Notes</span>
            </button>

            <button type="button" wire:click="showTrash"
                @if ($isTrashView) aria-current="page" @endif
                aria-label="{{ __('Trash') }}"
                :title="sidebarCollapsed ? @js(__('Trash')) : null"
                class="flex min-h-11 w-full items-center gap-2.5 rounded-lg px-3 py-2 text-start hover:bg-zinc-100 dark:hover:bg-zinc-700/60 {{ $isTrashView ? 'bg-zinc-200 dark:bg-zinc-700' : '' }}"
                :class="sidebarCollapsed && 'lg:justify-center lg:px-0'">
                <flux:icon.trash class="size-5 shrink-0 text-zinc-500 dark:text-zinc-400" aria-hidden="true" />
                <span class="min-w-0 truncate" :class="sidebarCollapsed && 'lg:hidden'">Trash</span>
            </button>

            <div class="flex items-center justify-between gap-1 pt-2"
                :class="sidebarCollapsed && 'lg:justify-center'">
                <h2 id="folders-heading" class="px-1 text-xs font-medium text-zinc-500 dark:text-zinc-400"
                    :class="sidebarCollapsed && 'lg:hidden'">
                    {{ __('Folders') }}
                </h2>

                <flux:button type="button" variant="ghost" size="sm" icon="plus" class="min-h-11 min-w-11"
                    aria-label="{{ __('New folder') }}"
                    wire:click="openCreateFolderModal">
                    <span :class="sidebarCollapsed && 'lg:hidden'">{{ __('New folder') }}</span>
                </flux:button>
            </div>

            @foreach ($folders as $folder)
                <div wire:key="folder-{{ $folder->id }}" class="flex items-center gap-1">
                    <button type="button" wire:click="showNotes; selectFolder({{ $folder->id }})"
                        @if ($selectedFolderId === $folder->id && !$isTrashView) aria-current="page" @endif
                        aria-label="{{ $folder->name }}"
                        :title="sidebarCollapsed ? @js($folder->name) : null"
                        class="flex min-h-11 min-w-0 flex-1 items-center gap-2.5 rounded-lg px-3 py-2 text-start hover:bg-zinc-100 dark:hover:bg-zinc-700/60 {{ $selectedFolderId === $folder->id && !$isTrashView ? 'bg-zinc-200 dark:bg-zinc-700' : '' }}"
                        :class="sidebarCollapsed && 'lg:justify-center lg:px-0'">
                        <flux:icon.folder class="hidden size-5 shrink-0 text-zinc-500 dark:text-zinc-400"
                            x-bind:class="sidebarCollapsed && 'lg:block'" aria-hidden="true" />
                        <span class="min-w-0 truncate" :class="sidebarCollapsed && 'lg:hidden'">{{ $folder->name }}</span>
                    </button>

                    <flux:dropdown position="bottom" align="end" x-bind:class="sidebarCollapsed && 'lg:hidden'">
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
        </aside>

        {{-- Notes --}}
        <section class="min-w-0 flex-1 lg:grid lg:grid-cols-[minmax(16rem,22rem)_minmax(0,1fr)] lg:items-start lg:gap-6" aria-labelledby="note-list-heading">
            <div class="min-w-0" data-note-list :class="mobileView === 'editor' ? 'max-lg:hidden' : ''">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <h2 id="note-list-heading" x-ref="noteListHeading" tabindex="-1" class="text-lg font-semibold focus:outline-none">
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
                    <label for="notes-search" class="mb-1 block text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Search notes') }}</label>
                    <input id="notes-search" type="search" wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search notes...') }}"
                        class="min-h-11 w-full rounded-lg border px-4 py-2">
                </div>

                <div>
                    <label for="notes-sort" class="mb-1 block text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Sort by') }}</label>
                    <select id="notes-sort" wire:model.live="sortBy" class="min-h-11 w-full rounded-lg border px-4 py-2">
                        <option value="newest">{{ __('Newest') }}</option>
                        <option value="oldest">{{ __('Oldest') }}</option>
                        <option value="title_asc">{{ __('Title A-Z') }}</option>
                        <option value="title_desc">{{ __('Title Z-A') }}</option>
                    </select>
                </div>
            </div>

            @if ($searchCount !== null)
                <div class="mb-4 flex items-center justify-between gap-3">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __(':count notes', ['count' => $searchCount]) }}
                    </p>
                    <button type="button" wire:click="$set('search', '')"
                        aria-label="{{ __('Clear search') }}"
                        class="min-h-11 rounded-lg border px-4 py-2 text-sm font-medium hover:bg-zinc-100 dark:hover:bg-zinc-700/60">
                        {{ __('Clear') }}
                    </button>
                </div>
            @endif

            <p id="notes-list-status" data-status-message-id="{{ $listMessageId }}" role="status"
                aria-live="polite" aria-atomic="true" class="sr-only">
                {{ $listStatusMessage }}
            </p>

            @if ($unavailableNote)
                <p class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100" role="status">
                    {{ __('This note is no longer available') }}
                </p>
            @endif

            @if ($notes->isEmpty())
                <div id="notes-empty-state" class="px-4 py-16 text-center">
                    @if (trim($search) !== '')
                        <p class="text-base font-semibold text-zinc-900 dark:text-zinc-50">{{ __('No matching notes') }}</p>

                        <button type="button" wire:click="$set('search', '')"
                            class="mt-5 min-h-11 rounded-lg border px-4 py-2 text-sm font-medium hover:bg-zinc-100 dark:hover:bg-zinc-700/60">{{ __('Clear search') }}</button>
                    @elseif ($isTrashView)
                        <p class="text-base font-semibold text-zinc-900 dark:text-zinc-50">{{ __('Trash is empty') }}</p>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Deleted notes appear here.') }}</p>
                    @else
                        <p class="text-base font-semibold text-zinc-900 dark:text-zinc-50">{{ __('No notes yet') }}</p>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Capture your first thought') }}</p>

                        <button type="button" wire:click="createNote"
                            class="mt-5 min-h-11 rounded-lg bg-black px-4 py-2 text-sm font-medium text-white">{{ __('New note') }}</button>
                    @endif
                </div>
            @else
                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($notes as $note)
                        <article wire:key="note-row-{{ $note->id }}" class="py-3">
                            <div class="flex flex-wrap items-start gap-x-3">
                                @if (!$isTrashView)
                                    <button type="button" id="note-row-{{ $note->id }}"
                                        wire:click="selectNote({{ $note->id }})"
                                        @if ($selectedNoteId === $note->id) aria-current="true" @endif
                                        class="min-w-0 flex-1 rounded px-4 py-1 text-left focus-visible:outline-3 focus-visible:outline-offset-4 {{ $selectedNoteId === $note->id ? 'bg-zinc-100 dark:bg-zinc-700' : 'hover:bg-zinc-50 dark:hover:bg-zinc-700/40' }}">
                                        <span class="block truncate text-sm font-semibold text-zinc-900 dark:text-zinc-50">{{ $note->title }}</span>

                                        <span class="mt-0.5 block truncate text-sm text-zinc-500 dark:text-zinc-400">
                                            {{ \Illuminate\Support\Str::limit(html_entity_decode(strip_tags($note->content ?? '')), 120) }}
                                        </span>
                                    </button>
                                @else
                                    <div id="note-row-{{ $note->id }}" class="min-w-0 flex-1 px-4 py-1">
                                        <span class="block truncate text-sm font-semibold text-zinc-900 dark:text-zinc-50">{{ $note->title }}</span>

                                        <p class="mt-0.5 block truncate text-sm text-zinc-500 dark:text-zinc-400">
                                            {{ \Illuminate\Support\Str::limit(html_entity_decode(strip_tags($note->content ?? '')), 120) }}
                                        </p>
                                    </div>
                                @endif

                                <time datetime="{{ $note->updated_at->utc()->toIso8601String() }}"
                                    data-updated-at="{{ $note->updated_at->utc()->toIso8601String() }}"
                                    x-data
                                    x-text="window.noteDates.formatNoteDate($el.dataset.updatedAt)"
                                    class="mt-0.5 block w-full shrink-0 px-4 text-xs text-zinc-500 dark:text-zinc-400 sm:mt-0 sm:w-auto sm:text-right">
                                    {{ $note->updated_at->format('M j, Y') }}
                                </time>
                            </div>
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

                                    <flux:dropdown position="bottom" align="start">
                                        <flux:button type="button" variant="ghost" size="sm" icon="ellipsis-horizontal"
                                            class="min-h-11 min-w-11"
                                            aria-label="{{ __('Reminder actions') }}"
                                            title="{{ __('Reminder actions') }}" />

                                        <flux:menu>
                                            @if ($isTrashView)
                                                <flux:menu.item as="button" class="min-h-11" wire:click="clearReminder({{ $note->id }})">
                                                    {{ __('Clear reminder') }}
                                                </flux:menu.item>
                                            @else
                                                <flux:menu.item as="button" class="min-h-11" wire:click="openReminderModal({{ $note->id }})">
                                                    {{ __('Change') }}
                                                </flux:menu.item>
                                                @if ($note->reminder_status === 'failed')
                                                    <flux:menu.item as="button" class="min-h-11" wire:click="retryReminder({{ $note->id }})">
                                                        {{ __('Retry now') }}
                                                    </flux:menu.item>
                                                @endif
                                                <flux:menu.item as="button" class="min-h-11" wire:click="clearReminder({{ $note->id }})">
                                                    {{ __('Clear') }}
                                                </flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            @elseif (!$isTrashView)
                                <button
                                    type="button"
                                    wire:click.stop="openReminderModal({{ $note->id }})"
                                    class="mt-2 min-h-11 rounded-lg px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-zinc-200 dark:hover:bg-zinc-700"
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

            @if ($hasMoreNotes)
                <div class="mt-4">
                    <button type="button" id="load-more-notes" wire:click="loadMoreNotes"
                        wire:loading.attr="disabled" wire:target="loadMoreNotes"
                        class="min-h-11 rounded-lg border px-4 py-2 text-sm font-medium hover:bg-zinc-100 dark:hover:bg-zinc-700/60">
                        {{ __('Load more') }}
                    </button>
                </div>
            @endif
            </div>

            @if ($selectedNote)
                <div class="mt-8 min-w-0 rounded-xl border p-6 sm:p-8 lg:mt-0" :class="mobileView === 'list' ? 'max-lg:hidden' : ''">
                    <div class="mb-4 flex items-center gap-3">
                        <button type="button" x-on:click="backToList()"
                            class="min-h-11 rounded-lg border px-3 py-2 text-sm font-medium lg:hidden">
                            {{ __('Back to notes') }}
                        </button>

                        <h2 id="note-editor-heading" x-ref="editorHeading" tabindex="-1" class="text-lg font-semibold focus:outline-none">
                            {{ __('Edit note') }}
                        </h2>
                    </div>

                    <label for="note-title" class="mb-1 block text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Title') }}</label>
                    <input id="note-title" type="text" wire:model="title"
                        aria-describedby="note-title-error" aria-invalid="{{ $errors->has('title') ? 'true' : 'false' }}"
                        class="mb-1 min-h-11 w-full border-0 bg-transparent px-0 py-1 text-3xl font-semibold">

                    @error('title')
                        <p id="note-title-error" role="alert" class="mb-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    <label for="note-folder" class="mb-1 mt-3 block text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Folder') }}</label>
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
                        <div class="mb-3 border-b border-zinc-200 pb-2 dark:border-zinc-700" role="toolbar" aria-label="{{ __('Formatting') }}"
                            aria-describedby="note-toolbar-help">
                            <div class="flex flex-wrap items-center gap-1">
                                <div class="flex items-center gap-1">
                                    <x-notes.toolbar-button label="Bold" action="toggleBold()" :pressed="'activeFormats.bold'" glyph="B" class="font-bold" />
                                    <x-notes.toolbar-button label="Italic" action="toggleItalic()" :pressed="'activeFormats.italic'" glyph="I" class="italic" />
                                    <x-notes.toolbar-button label="Underline" action="toggleUnderline()" :pressed="'activeFormats.underline'" glyph="U" class="underline" />
                                    <x-notes.toolbar-button label="Strikethrough" action="toggleStrike()" :pressed="'activeFormats.strike'" glyph="S" class="line-through" />
                                </div>

                                <div class="flex max-md:hidden items-center gap-1 border-l border-zinc-200 pl-2 dark:border-zinc-700">
                                    <x-notes.toolbar-button label="Code block" action="toggleCodeBlock()" :pressed="'activeFormats.codeBlock'" glyph="Code" class="max-md:hidden" />
                                    <x-notes.toolbar-button label="Bullet list" action="toggleBulletList()" :pressed="'activeFormats.bulletList'" glyph="Bullets" class="max-md:hidden" />
                                    <x-notes.toolbar-button label="Numbered list" action="toggleOrderedList()" :pressed="'activeFormats.orderedList'" glyph="Numbers" class="max-md:hidden" />
                                    <x-notes.toolbar-button label="Task list" action="toggleTaskList()" :pressed="'activeFormats.taskList'" glyph="Tasks" class="max-md:hidden" />
                                </div>

                                <div class="flex max-md:hidden items-center gap-1 border-l border-zinc-200 pl-2 dark:border-zinc-700">
                                    <x-notes.toolbar-button label="Paragraph" action="setParagraph()" :pressed="'activeFormats.paragraph'" glyph="P" class="max-md:hidden" />
                                    <x-notes.toolbar-button label="Heading 1" action="setHeading(1)" :pressed="'activeFormats.heading1'" glyph="H1" class="max-md:hidden font-bold" />
                                    <x-notes.toolbar-button label="Heading 2" action="setHeading(2)" :pressed="'activeFormats.heading2'" glyph="H2" class="max-md:hidden font-bold" />
                                    <x-notes.toolbar-button label="Heading 3" action="setHeading(3)" :pressed="'activeFormats.heading3'" glyph="H3" class="max-md:hidden font-bold" />
                                </div>

                                <div class="flex max-md:hidden items-center gap-1 border-l border-zinc-200 pl-2 dark:border-zinc-700">
                                    <select aria-label="{{ __('Font size') }}" :value="activeFormats.fontSize" @change="setFontSize($event.target.value)"
                                        class="max-md:hidden min-h-6 rounded border bg-white px-2 py-1 text-sm dark:bg-zinc-900">
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
                                        class="max-md:hidden h-6 w-8 cursor-pointer rounded border bg-white p-0.5 dark:bg-zinc-900" />
                                </div>

                                <div class="flex max-md:hidden items-center gap-1 border-l border-zinc-200 pl-2 dark:border-zinc-700">
                                    <x-notes.toolbar-button label="Highlight" action="toggleHighlight()" :pressed="'activeFormats.highlight'" glyph="Mark" class="max-md:hidden" />
                                    <x-notes.toolbar-button label="Link" action="setLink()" :pressed="'activeFormats.link'" glyph="Link" class="max-md:hidden" />
                                </div>

                                <div class="flex max-md:hidden items-center gap-1 border-l border-zinc-200 pl-2 dark:border-zinc-700">
                                    <x-notes.toolbar-button label="Align left" action="setTextAlign('left')" :pressed="'activeFormats.alignLeft'" glyph="Left" class="max-md:hidden" />
                                    <x-notes.toolbar-button label="Align center" action="setTextAlign('center')" :pressed="'activeFormats.alignCenter'" glyph="Center" class="max-md:hidden" />
                                    <x-notes.toolbar-button label="Align right" action="setTextAlign('right')" :pressed="'activeFormats.alignRight'" glyph="Right" class="max-md:hidden" />
                                    <x-notes.toolbar-button label="Justify" action="setTextAlign('justify')" :pressed="'activeFormats.alignJustify'" glyph="Justify" class="max-md:hidden" />
                                </div>

                                <div class="flex max-md:hidden items-center gap-1 border-l border-zinc-200 pl-2 dark:border-zinc-700">
                                    <x-notes.toolbar-button label="Insert table" action="insertTable()" glyph="Table" class="max-md:hidden" />

                                    <div x-show="activeFormats.table" x-data="{ tableMenuOpen: false }" class="relative max-md:hidden">
                                        <button type="button" @mousedown.prevent @click="tableMenuOpen = ! tableMenuOpen"
                                            :aria-expanded="tableMenuOpen ? 'true' : 'false'" aria-haspopup="true"
                                            aria-label="{{ __('Table options') }}"
                                            class="inline-flex min-h-6 items-center rounded px-3 py-1 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700">
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
                                            class="inline-flex min-h-6 items-center rounded px-3 py-1 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700">
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
                                </div>

                                <div class="relative border-l border-zinc-200 pl-2 dark:border-zinc-700 md:hidden" x-data="{ moreMenuOpen: false }">
                                    <button type="button" @mousedown.prevent @click="moreMenuOpen = ! moreMenuOpen"
                                        :aria-expanded="moreMenuOpen ? 'true' : 'false'" aria-haspopup="true"
                                        aria-label="{{ __('More formatting') }}"
                                        class="inline-flex min-h-6 items-center rounded px-3 py-1 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700">
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

                                        <label for="more-font-size" class="mt-1 block px-3 py-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Font size') }}</label>
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

                                        <label for="more-font-color" class="block px-3 py-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Font color') }}</label>
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
                                            <p class="mt-1 px-3 py-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Table options') }}</p>
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

                                            <p class="mt-1 px-3 py-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Table width') }}</p>
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
                        <div x-ref="editor" class="w-full"></div>
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
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ number_format($noteAttachment->file_size / 1024, 1) }} KB</p>
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
                                <p class="p-3 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No attachments yet.') }}</p>
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
                                <p id="attachment-help" class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ __('PDF, Word, text, spreadsheet, presentation, or image up to 10 MB.') }}
                                </p>
                            </div>

                            <button type="submit" wire:loading.attr="disabled"
                                wire:target="uploadAttachment, attachment"
                                class="min-h-11 rounded-lg bg-black px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                                {{ __('Upload file') }}
                            </button>
                        </form>

                        <p wire:loading wire:target="attachment" aria-hidden="true" class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
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
                <flux:button type="submit" variant="primary" class="[--color-accent:black] [--color-accent-foreground:white]" wire:loading.attr="disabled" wire:target="saveFolder">
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
                        <flux:button type="submit" variant="primary" class="[--color-accent:black] [--color-accent-foreground:white]" wire:loading.attr="disabled">
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
                <p x-show="!quickCaptureCanSave" class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Write something to capture.') }}
                </p>
                <flux:error id="quick-capture-error" name="quickCaptureText" role="alert" />
            </flux:field>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
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

                    <flux:button type="submit" variant="primary" class="[--color-accent:black] [--color-accent-foreground:white]"
                        x-bind:disabled="quickCaptureSaving || !quickCaptureCanSave">
                        {{ __('Save') }}
                    </flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
