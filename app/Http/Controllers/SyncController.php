<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\Note;
use App\Models\SyncChange;
use App\Models\User;
use App\Sync\ChangeRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class SyncController extends Controller
{
    private const NOTE_ACTIONS = ['created', 'updated', 'deleted', 'restored', 'purged'];

    private const FOLDER_ACTIONS = ['created', 'updated', 'deleted'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        if ($throttled = $this->throttle($user)) {
            return $throttled;
        }

        $rules = [
            'since' => ['required', 'integer', 'min:0'],
        ];

        if ((int) $request->input('since') !== 0) {
            $rules['limit'] = ['sometimes', 'integer', 'between:1,1000'];
        }

        $validated = $request->validate($rules);

        $since = (int) $validated['since'];

        if ($since === 0) {
            return response()->json($this->snapshot($user));
        }

        $result = $this->changesSince($user, $since, (int) ($validated['limit'] ?? 200));

        return $result instanceof JsonResponse ? $result : response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        if ($throttled = $this->throttle($user)) {
            return $throttled;
        }

        $validated = $request->validate([
            'changes' => ['required', 'array', 'max:100'],
            'changes.*.client_change_id' => ['required', 'uuid'],
            'changes.*.entity_type' => ['required', 'in:note,folder'],
            'changes.*.action' => ['required', 'string'],
            'changes.*.portable_id' => ['required', 'uuid'],
            'changes.*.base_version' => ['required', 'integer', 'min:0'],
            'changes.*.payload' => ['sometimes', 'array'],
        ]);

        $results = [];
        $touchedNotes = [];
        $touchedFolders = [];
        $deletedFolderPortableIds = [];
        $deletedNotePortableIds = [];

        foreach ($validated['changes'] as $change) {
            $result = $this->applyOne($user, $change);
            $results[] = $result;

            if (($result['note']['portable_id'] ?? null) !== null && $result['note'] !== null) {
                $touchedNotes[$result['note']['portable_id']] = $result['note'];
            }

            if (($result['folder']['portable_id'] ?? null) !== null && $result['folder'] !== null) {
                $touchedFolders[$result['folder']['portable_id']] = $result['folder'];
            }

            if ($result['status'] === 'applied') {
                if (($change['entity_type'] ?? null) === 'folder'
                    && ($change['action'] ?? null) === 'deleted') {
                    $deletedFolderPortableIds[] = $change['portable_id'];
                }

                if (($change['entity_type'] ?? null) === 'note'
                    && ($change['action'] ?? null) === 'purged') {
                    $deletedNotePortableIds[] = $change['portable_id'];
                }
            }
        }

        return response()->json([
            'server_revision' => $this->currentRevision($user),
            'results' => $results,
            'notes' => array_values($touchedNotes),
            'folders' => array_values($touchedFolders),
            'deleted_folder_portable_ids' => $deletedFolderPortableIds,
            'deleted_note_portable_ids' => $deletedNotePortableIds,
        ]);
    }

    private function snapshot(User $user): array
    {
        $revision = $this->currentRevision($user);

        $notes = $user->notes()
            ->withTrashed()
            ->with(['folder', 'attachments'])
            ->orderBy('id')
            ->get()
            ->map(fn (Note $note): array => $this->noteState($note));

        $folders = $user->folders()
            ->orderBy('id')
            ->get()
            ->map(fn (Folder $folder): array => $this->folderState($folder));

        return [
            'revision' => $revision,
            'notes' => $notes,
            'folders' => $folders,
            'deleted_folder_portable_ids' => [],
            'deleted_note_portable_ids' => [],
            'has_more' => false,
        ];
    }

    private function changesSince(User $user, int $since, int $limit): array|JsonResponse
    {
        $minRetained = SyncChange::query()
            ->where('user_id', $user->getKey())
            ->min('id');

        if ($minRetained !== null && $since < $minRetained - 1) {
            return response()->json([
                'revision' => $minRetained,
                'notes' => [],
                'folders' => [],
                'deleted_folder_portable_ids' => [],
                'deleted_note_portable_ids' => [],
                'has_more' => false,
            ], 410);
        }

        $rows = SyncChange::query()
            ->where('user_id', $user->getKey())
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;

        if ($hasMore) {
            $rows = $rows->take($limit);
        }

        $notePortableIds = $rows
            ->where('entity_type', 'note')
            ->where('action', '!=', 'purged')
            ->pluck('entity_portable_id')
            ->unique();

        $folderPortableIds = $rows
            ->where('entity_type', 'folder')
            ->where('action', '!=', 'deleted')
            ->pluck('entity_portable_id')
            ->unique();

        $notes = $user->notes()
            ->withTrashed()
            ->with(['folder', 'attachments'])
            ->whereIn('portable_id', $notePortableIds)
            ->get()
            ->map(fn (Note $note): array => $this->noteState($note));

        $folders = $user->folders()
            ->whereIn('portable_id', $folderPortableIds)
            ->get()
            ->map(fn (Folder $folder): array => $this->folderState($folder));

        return [
            'revision' => $rows->count() > 0 ? (int) $rows->last()->id : $since,
            'notes' => $notes,
            'folders' => $folders,
            'deleted_folder_portable_ids' => $rows
                ->where('entity_type', 'folder')
                ->where('action', 'deleted')
                ->pluck('entity_portable_id')
                ->unique()
                ->values(),
            'deleted_note_portable_ids' => $rows
                ->where('entity_type', 'note')
                ->where('action', 'purged')
                ->pluck('entity_portable_id')
                ->unique()
                ->values(),
            'has_more' => $hasMore,
        ];
    }

    /**
     * Change rows record automatic model events, so the sync write path
     * suppresses observers and stamps the change log itself.
     */
    private function applyOne(User $user, array $change): array
    {
        try {
            return DB::transaction(function () use ($user, $change): array {
                $existing = SyncChange::query()
                    ->where('user_id', $user->getKey())
                    ->where('client_change_id', $change['client_change_id'])
                    ->first();

                if ($existing !== null && $existing->status !== null) {
                    return $this->replay($user, $existing);
                }

                if ($change['entity_type'] === 'note') {
                    return $this->applyNoteChange($user, $change);
                }

                return $this->applyFolderChange($user, $change);
            });
        } catch (\Throwable $exception) {
            report($exception);

            $record = SyncChange::record($user->getKey(), $change['entity_type'], $change['portable_id'], $change['action'], $change['client_change_id']);
            $record->update([
                'status' => 'rejected',
                'error' => 'This change could not be applied.',
            ]);

            return [
                'client_change_id' => $change['client_change_id'],
                'status' => 'rejected',
                'note' => null,
                'folder' => null,
                'conflict_note' => null,
                'message' => 'This change could not be applied.',
            ];
        }
    }

    private function recordChange(User $user, string $entityType, string $portableId, string $action, ?string $clientChangeId = null): void
    {
        SyncChange::record($user->getKey(), $entityType, $portableId, $action, $clientChangeId)
            ->update(['status' => 'applied']);
    }

    private function applyNoteChange(User $user, array $change): array
    {
        $action = $change['action'];
        $portableId = $change['portable_id'];
        $payload = $change['payload'] ?? [];
        $baseVersion = (int) $change['base_version'];

        if (! in_array($action, self::NOTE_ACTIONS, true)) {
            return $this->reject($user, $change, "Unsupported note action: {$action}.");
        }

        if (! $this->notePayloadIsWellFormed($payload)) {
            return $this->reject($user, $change, 'This note change is malformed.');
        }

        return ChangeRecorder::withoutRecording(function () use ($user, $change, $action, $portableId, $payload, $baseVersion): array {
            $note = $user->notes()
                ->withTrashed()
                ->where('portable_id', $portableId)
                ->first();

            if ($action === 'created') {
                return $this->createNote($user, $change, $note, $payload);
            }

            if ($note === null) {
                return $this->reject($user, $change, 'Note not found.');
            }

            return match ($action) {
                'updated' => $this->updateNote($user, $change, $note, $payload, $baseVersion),
                'deleted' => $this->trashNote($user, $change, $note),
                'restored' => $this->restoreNote($user, $change, $note),
                'purged' => $this->purgeNote($user, $change, $note),
                default => $this->reject($user, $change, 'This note change is not supported.'),
            };
        });
    }

    private function notePayloadIsWellFormed(array $payload): bool
    {
        foreach (['title', 'content', 'folder_portable_id'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            if ($payload[$key] !== null && ! is_string($payload[$key])) {
                return false;
            }
        }

        return true;
    }

    private function createNote(User $user, array $change, ?Note $existing, array $payload): array
    {
        $portableId = $change['portable_id'];

        if ($existing !== null) {
            SyncChange::record($user->getKey(), 'note', $portableId, 'created', $change['client_change_id'])
                ->update(['status' => 'applied']);

            return $this->applied($this->noteState($existing), $change['client_change_id']);
        }

        $title = $payload['title'] ?? null;

        if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 255) {
            return $this->reject($user, $change, 'A note needs a title of up to 255 characters.');
        }

        $folder = $this->resolveFolder($user, $payload['folder_portable_id'] ?? null, $folderError);

        if (($payload['folder_portable_id'] ?? null) !== null && $folder === null) {
            return $this->reject($user, $change, $folderError);
        }

        $note = $user->notes()->create([
            'portable_id' => $portableId,
            'folder_id' => $folder?->getKey(),
            'title' => $title,
            'content' => $payload['content'] ?? null,
        ]);

        SyncChange::record($user->getKey(), 'note', $portableId, 'created', $change['client_change_id'])
            ->update(['status' => 'applied']);

        return $this->applied($this->noteState($note->fresh(['folder', 'attachments'])), $change['client_change_id']);
    }

    private function updateNote(User $user, array $change, Note $note, array $payload, int $baseVersion): array
    {
        $note->load(['folder', 'attachments']);

        $title = $payload['title'] ?? $note->title;

        if (array_key_exists('title', $payload)
            && (! is_string($payload['title']) || trim($payload['title']) === '' || mb_strlen($payload['title']) > 255)) {
            return $this->reject($user, $change, 'A note needs a title of up to 255 characters.');
        }

        $folderProvided = array_key_exists('folder_portable_id', $payload);
        $folder = $folderProvided
            ? $this->resolveFolder($user, $payload['folder_portable_id'], $folderError)
            : $note->folder;

        if ($folderProvided && $payload['folder_portable_id'] !== null && $folder === null) {
            return $this->reject($user, $change, $folderError);
        }

        if ($note->trashed()) {
            if ($this->contentMatches($note, $payload)) {
                if ($folderProvided) {
                    $this->applyFolderMove($user, $note, $folder, $change['client_change_id']);
                }

                return $this->applied($this->noteState($note->fresh(['folder', 'attachments'])), $change['client_change_id']);
            }

            return $this->conflict($user, $change, $note, $payload);
        }

        if ($baseVersion !== $note->version) {
            if ($this->contentMatches($note, $payload)) {
                if ($folderProvided) {
                    $this->applyFolderMove($user, $note, $folder, $change['client_change_id']);
                }

                return $this->applied($this->noteState($note->fresh(['folder', 'attachments'])), $change['client_change_id']);
            }

            return $this->conflict($user, $change, $note, $payload);
        }

        $note->forceFill([
            'title' => $title,
            'content' => $payload['content'] ?? $note->content,
            'folder_id' => $folderProvided ? $folder?->getKey() : $note->folder_id,
        ])->save();
        $this->bumpNoteVersion($note);
        $this->recordChange($user, 'note', $change['portable_id'], 'updated', $change['client_change_id']);

        return $this->applied($this->noteState($note->fresh(['folder', 'attachments'])), $change['client_change_id']);
    }

    private function trashNote(User $user, array $change, Note $note): array
    {
        if (! $note->trashed()) {
            $note->delete();
            $note->newQuery()->withTrashed()->whereKey($note->getKey())->increment('version');
            $note->version += 1;
            $this->recordChange($user, 'note', $change['portable_id'], 'deleted', $change['client_change_id']);
        }

        return $this->applied($this->noteState($note->fresh(['folder', 'attachments'])), $change['client_change_id']);
    }

    private function restoreNote(User $user, array $change, Note $note): array
    {
        if ($note->trashed()) {
            $note->restore();
            $note->newQuery()->withTrashed()->whereKey($note->getKey())->increment('version');
            $note->version += 1;
            $this->recordChange($user, 'note', $change['portable_id'], 'restored', $change['client_change_id']);
        }

        return $this->applied($this->noteState($note->fresh(['folder', 'attachments'])), $change['client_change_id']);
    }

    private function purgeNote(User $user, array $change, Note $note): array
    {
        if (! $note->trashed()) {
            return $this->reject($user, $change, 'A note must be in Trash before it can be permanently deleted.');
        }

        $note->forceDelete();

        $record = SyncChange::record($user->getKey(), 'note', $change['portable_id'], 'purged', $change['client_change_id']);
        $record->update(['status' => 'applied']);

        return [
            'client_change_id' => $change['client_change_id'],
            'status' => 'applied',
            'note' => null,
            'folder' => null,
            'conflict_note' => null,
            'message' => null,
        ];
    }

    private function conflict(User $user, array $change, Note $serverNote, array $payload): array
    {
        $prefix = 'Conflict copy of ';
        $titleSource = $payload['title'] ?? $serverNote->title;
        $titleSource = is_string($titleSource) && $titleSource !== '' ? $titleSource : $serverNote->title;
        $copyTitle = $prefix.mb_substr($titleSource, 0, 255 - mb_strlen($prefix));

        $copy = $user->notes()->create([
            'portable_id' => $change['client_change_id'],
            'folder_id' => null,
            'title' => $copyTitle,
            'content' => $payload['content'] ?? null,
        ]);

        SyncChange::record($user->getKey(), 'note', $copy->portable_id, 'created', null)
            ->update(['status' => 'applied']);

        $receipt = SyncChange::record($user->getKey(), 'note', $change['portable_id'], 'updated', $change['client_change_id']);
        $receipt->update([
            'status' => 'conflict',
            'conflict_note_portable_id' => $copy->portable_id,
        ]);

        return [
            'client_change_id' => $change['client_change_id'],
            'status' => 'conflict',
            'note' => $this->noteState($serverNote->fresh(['folder', 'attachments'])),
            'folder' => null,
            'conflict_note' => $this->noteState($copy->fresh(['folder', 'attachments'])),
            'message' => 'This note changed on another device. Your offline version was kept as a conflict copy.',
        ];
    }

    private function applyFolderMove(User $user, Note $note, ?Folder $folder, string $clientChangeId): void
    {
        if (($folder?->getKey() ?? null) === $note->folder_id) {
            return;
        }

        $note->forceFill(['folder_id' => $folder?->getKey()])->save();
        $this->bumpNoteVersion($note);
        $this->recordChange($user, 'note', $note->portable_id, 'updated', $clientChangeId);
    }

    private function contentMatches(Note $note, array $payload): bool
    {
        $title = $payload['title'] ?? null;
        $content = $payload['content'] ?? null;

        $titleMatches = ! is_string($title) || $title === $note->title;
        $contentMatches = ! is_string($content) || $content === ($note->content ?? '');

        return $titleMatches && $contentMatches;
    }

    private function resolveFolder(User $user, mixed $folderPortableId, ?string &$error = null): ?Folder
    {
        $error = null;

        if ($folderPortableId === null) {
            return null;
        }

        if (! is_string($folderPortableId) || ! Str::isUuid($folderPortableId)) {
            $error = 'Folder not found.';

            return null;
        }

        $folder = $user->folders()->where('portable_id', $folderPortableId)->first();

        if ($folder === null) {
            $error = 'Folder not found.';

            return null;
        }

        return $folder;
    }

    private function applyFolderChange(User $user, array $change): array
    {
        $action = $change['action'];
        $portableId = $change['portable_id'];
        $payload = $change['payload'] ?? [];
        $baseVersion = (int) $change['base_version'];

        if (! in_array($action, self::FOLDER_ACTIONS, true)) {
            return $this->reject($user, $change, "Unsupported folder action: {$action}.");
        }

        return ChangeRecorder::withoutRecording(function () use ($user, $change, $action, $portableId, $payload, $baseVersion): array {
            $folder = $user->folders()->where('portable_id', $portableId)->first();

            return match ($action) {
                'created' => $this->createFolder($user, $change, $folder, $payload),
                'updated' => $this->renameFolder($user, $change, $folder, $payload, $baseVersion),
                'deleted' => $this->deleteFolder($user, $change, $folder),
                default => $this->reject($user, $change, 'This folder change is not supported.'),
            };
        });
    }

    private function createFolder(User $user, array $change, ?Folder $existing, array $payload): array
    {
        if ($existing !== null) {
            SyncChange::record($user->getKey(), 'folder', $change['portable_id'], 'created', $change['client_change_id'])
                ->update(['status' => 'applied']);

            return $this->appliedFolder($this->folderState($existing), $change['client_change_id']);
        }

        $name = $payload['name'] ?? null;

        if (! is_string($name) || Folder::normalizeName($name) === '' || mb_strlen(Folder::normalizeName($name)) > 255) {
            return $this->reject($user, $change, 'A folder needs a name of up to 255 characters.');
        }

        $nameKey = Folder::nameKeyFor(Folder::normalizeName($name));

        if ($user->folders()->where('name_key', $nameKey)->exists()) {
            return $this->reject($user, $change, 'A folder with this name already exists.');
        }

        $folder = $user->folders()->create([
            'name' => $name,
            'portable_id' => $change['portable_id'],
        ]);

        SyncChange::record($user->getKey(), 'folder', $change['portable_id'], 'created', $change['client_change_id'])
            ->update(['status' => 'applied']);

        return $this->appliedFolder($this->folderState($folder->fresh()), $change['client_change_id']);
    }

    private function renameFolder(User $user, array $change, ?Folder $folder, array $payload, int $baseVersion): array
    {
        if ($folder === null) {
            return $this->reject($user, $change, 'Folder not found.');
        }

        if ($baseVersion !== $folder->version) {
            $record = SyncChange::record($user->getKey(), 'folder', $folder->portable_id, 'updated', $change['client_change_id']);
            $record->update([
                'status' => 'rejected',
                'error' => 'This folder was changed on another device. The latest name was kept.',
            ]);

            return [
                'client_change_id' => $change['client_change_id'],
                'status' => 'rejected',
                'note' => null,
                'folder' => $this->folderState($folder),
                'conflict_note' => null,
                'message' => 'This folder was changed on another device. The latest name was kept.',
            ];
        }

        $name = $payload['name'] ?? $folder->name;

        if (! is_string($name) || Folder::normalizeName($name) === '' || mb_strlen(Folder::normalizeName($name)) > 255) {
            return $this->reject($user, $change, 'A folder needs a name of up to 255 characters.');
        }

        $nameKey = Folder::nameKeyFor(Folder::normalizeName($name));

        $conflict = $user->folders()
            ->where('name_key', $nameKey)
            ->where('id', '!=', $folder->getKey())
            ->exists();

        if ($conflict) {
            return $this->reject($user, $change, 'A folder with this name already exists.');
        }

        $folder->update(['name' => $name]);
        $folder->newQuery()->whereKey($folder->getKey())->increment('version');
        $folder->version += 1;
        $this->recordChange($user, 'folder', $folder->portable_id, 'updated', $change['client_change_id']);

        return $this->appliedFolder($this->folderState($folder->fresh()), $change['client_change_id']);
    }

    private function deleteFolder(User $user, array $change, ?Folder $folder): array
    {
        if ($folder === null) {
            return $this->reject($user, $change, 'Folder not found.');
        }

        $affected = Note::withTrashed()
            ->where('folder_id', $folder->getKey())
            ->select(['id', 'portable_id', 'user_id'])
            ->get();

        Note::withTrashed()->where('folder_id', $folder->getKey())->increment('version');

        $folder->delete();

        foreach ($affected as $note) {
            SyncChange::record($note->user_id, 'note', $note->portable_id, 'updated', null)
                ->update(['status' => 'applied']);
        }

        SyncChange::record($user->getKey(), 'folder', $folder->portable_id, 'deleted', $change['client_change_id'])
            ->update(['status' => 'applied']);

        return [
            'client_change_id' => $change['client_change_id'],
            'status' => 'applied',
            'note' => null,
            'folder' => null,
            'conflict_note' => null,
            'message' => null,
        ];
    }

    private function reject(User $user, array $change, ?string $message): array
    {
        $record = SyncChange::record(
            $user->getKey(),
            $change['entity_type'],
            $change['portable_id'],
            $change['action'],
            $change['client_change_id'],
        );
        $record->update([
            'status' => 'rejected',
            'error' => $message,
        ]);

        return [
            'client_change_id' => $change['client_change_id'],
            'status' => 'rejected',
            'note' => null,
            'folder' => null,
            'conflict_note' => null,
            'message' => $message,
        ];
    }

    private function replay(User $user, SyncChange $existing): array
    {
        $note = null;
        $conflictNote = null;

        if ($existing->entity_type === 'note' && $existing->entity_portable_id !== null) {
            $note = $user->notes()
                ->withTrashed()
                ->with(['folder', 'attachments'])
                ->where('portable_id', $existing->entity_portable_id)
                ->first();

            if ($existing->conflict_note_portable_id !== null) {
                $conflictNote = $user->notes()
                    ->withTrashed()
                    ->with(['folder', 'attachments'])
                    ->where('portable_id', $existing->conflict_note_portable_id)
                    ->first();
            }
        }

        $folder = null;

        if ($existing->entity_type === 'folder' && $existing->entity_portable_id !== null) {
            $folder = $user->folders()
                ->where('portable_id', $existing->entity_portable_id)
                ->first();
        }

        return [
            'client_change_id' => $existing->client_change_id,
            'status' => $existing->status ?? 'applied',
            'note' => $note === null ? null : $this->noteState($note),
            'folder' => $folder === null ? null : $this->folderState($folder),
            'conflict_note' => $conflictNote === null ? null : $this->noteState($conflictNote),
            'message' => $existing->error,
        ];
    }

    private function applied(array $noteState, ?string $clientChangeId = null): array
    {
        return [
            'client_change_id' => $clientChangeId,
            'status' => 'applied',
            'note' => $noteState,
            'folder' => null,
            'conflict_note' => null,
            'message' => null,
        ];
    }

    private function appliedFolder(array $folderState, ?string $clientChangeId = null): array
    {
        return [
            'client_change_id' => $clientChangeId,
            'status' => 'applied',
            'note' => null,
            'folder' => $folderState,
            'conflict_note' => null,
            'message' => null,
        ];
    }

    private function noteState(Note $note): array
    {
        $attachments = $note->attachments->map(fn ($attachment) => [
            'portable_id' => $attachment->portable_id,
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'file_size' => (int) $attachment->file_size,
        ])->values();

        return [
            'portable_id' => $note->portable_id,
            'folder_portable_id' => $note->folder?->portable_id,
            'title' => $note->title,
            'content' => $note->content ?? '',
            'version' => (int) $note->version,
            'deleted_at' => $note->deleted_at?->toISOString(),
            'created_at' => $note->created_at?->toISOString(),
            'updated_at' => $note->updated_at?->toISOString(),
            'attachments' => $attachments,
        ];
    }

    private function folderState(Folder $folder): array
    {
        return [
            'portable_id' => $folder->portable_id,
            'name' => $folder->name,
            'version' => (int) $folder->version,
            'updated_at' => $folder->updated_at?->toISOString(),
        ];
    }

    private function currentRevision(User $user): int
    {
        return (int) (SyncChange::query()
            ->where('user_id', $user->getKey())
            ->max('id') ?? 0);
    }

    private function bumpNoteVersion(Note $note): void
    {
        Note::withTrashed()->whereKey($note->getKey())->increment('version');
        $note->version += 1;
    }

    private function throttle(User $user): ?JsonResponse
    {
        $key = 'sync:'.$user->getAuthIdentifier();
        $attempts = RateLimiter::increment($key);

        if ($attempts <= 60) {
            return null;
        }

        $retryAfter = RateLimiter::availableIn($key);

        return response()->json([
            'message' => "Too many sync attempts. Try again in {$retryAfter} seconds.",
            'retry_after' => $retryAfter,
        ], 429, ['Retry-After' => (string) $retryAfter]);
    }
}
