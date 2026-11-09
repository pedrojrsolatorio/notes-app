<?php

namespace App\Observers;

use App\Models\Folder;
use App\Models\Note;
use App\Sync\ChangeRecorder;

class FolderObserver
{
    public function created(Folder $folder): void
    {
        if (! ChangeRecorder::shouldRecord()) {
            return;
        }

        ChangeRecorder::record(
            userId: $folder->user_id,
            entityType: 'folder',
            entityPortableId: $folder->portable_id,
            action: 'created',
        );
    }

    public function updated(Folder $folder): void
    {
        if (! ChangeRecorder::shouldRecord()) {
            return;
        }

        if ($folder->wasChanged('name')) {
            $folder->newQuery()->whereKey($folder->getKey())->increment('version');
            $folder->version += 1;
        }

        ChangeRecorder::record(
            userId: $folder->user_id,
            entityType: 'folder',
            entityPortableId: $folder->portable_id,
            action: 'updated',
        );
    }

    public function deleting(Folder $folder): void
    {
        if (! ChangeRecorder::shouldRecord()) {
            return;
        }

        $notes = Note::withTrashed()
            ->where('folder_id', $folder->getKey())
            ->select(['id', 'portable_id', 'user_id'])
            ->get();

        Note::withTrashed()->where('folder_id', $folder->getKey())->increment('version');

        foreach ($notes as $note) {
            ChangeRecorder::record(
                userId: $note->user_id,
                entityType: 'note',
                entityPortableId: $note->portable_id,
                action: 'updated',
            );
        }
    }

    public function deleted(Folder $folder): void
    {
        if (! ChangeRecorder::shouldRecord()) {
            return;
        }

        ChangeRecorder::record(
            userId: $folder->user_id,
            entityType: 'folder',
            entityPortableId: $folder->portable_id,
            action: 'deleted',
        );
    }
}
