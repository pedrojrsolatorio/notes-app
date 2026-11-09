<?php

namespace App\Observers;

use App\Models\Note;
use App\Sync\ChangeRecorder;

class NoteObserver
{
    public function created(Note $note): void
    {
        if (! ChangeRecorder::shouldRecord()) {
            return;
        }

        ChangeRecorder::record(
            userId: $note->user_id,
            entityType: 'note',
            entityPortableId: $note->portable_id,
            action: 'created',
        );
    }

    public function updated(Note $note): void
    {
        if (! ChangeRecorder::shouldRecord()) {
            return;
        }

        if ($note->wasChanged('title')
            || $note->wasChanged('content')
            || $note->wasChanged('folder_id')
            || $note->wasChanged('deleted_at')) {
            $this->bumpVersion($note);
        }

        if ($note->wasChanged('deleted_at')) {
            $action = $note->trashed() ? 'deleted' : 'restored';
        } else {
            $action = 'updated';
        }

        ChangeRecorder::record(
            userId: $note->user_id,
            entityType: 'note',
            entityPortableId: $note->portable_id,
            action: $action,
        );
    }

    public function deleted(Note $note): void
    {
        if (! ChangeRecorder::shouldRecord()) {
            return;
        }

        if ($note->exists) {
            $this->bumpVersion($note);

            $action = 'deleted';
        } else {
            $action = 'purged';
        }

        ChangeRecorder::record(
            userId: $note->user_id,
            entityType: 'note',
            entityPortableId: $note->portable_id,
            action: $action,
        );
    }

    private function bumpVersion(Note $note): void
    {
        Note::withTrashed()->whereKey($note->getKey())->increment('version');
        $note->version += 1;
    }
}
