<?php

namespace App\Observers;

use App\Models\Note;
use App\Models\NoteAttachment;
use App\Sync\ChangeRecorder;

class NoteAttachmentObserver
{
    public function created(NoteAttachment $attachment): void
    {
        $this->recordNoteChange($attachment);
    }

    public function deleted(NoteAttachment $attachment): void
    {
        $this->recordNoteChange($attachment);
    }

    private function recordNoteChange(NoteAttachment $attachment): void
    {
        if (! ChangeRecorder::shouldRecord()) {
            return;
        }

        $noteId = $attachment->note_id;

        if ($noteId === null) {
            $noteId = $attachment->note()->value('id');
        }

        if ($noteId === null) {
            return;
        }

        $note = Note::withTrashed()->find($noteId);

        if ($note === null) {
            return;
        }

        ChangeRecorder::record(
            userId: $note->user_id,
            entityType: 'note',
            entityPortableId: $note->portable_id,
            action: 'updated',
        );
    }
}
