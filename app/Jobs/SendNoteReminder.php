<?php

namespace App\Jobs;

use App\Mail\NoteReminderMail;
use App\Models\Note;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendNoteReminder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 240];

    public int $uniqueFor = 30;

    public function __construct(public int $noteId, public string $generation) {}

    public function uniqueId(): string
    {
        return "{$this->noteId}:{$this->generation}";
    }

    public function handle(): void
    {
        $delivery = DB::transaction(function (): ?array {
            $note = Note::withTrashed()->lockForUpdate()->find($this->noteId);

            if (
                $note === null
                || $note->reminder_generation !== $this->generation
                || $note->reminder_status !== 'queued'
                || $note->reminder_at === null
                || $note->reminder_at->isFuture()
            ) {
                return null;
            }

            if ($note->trashed()) {
                $this->clearReminder($note->id);

                return null;
            }

            $user = User::query()->lockForUpdate()->find($note->user_id);

            if ($user === null) {
                $this->clearReminder($note->id);

                return null;
            }

            if (! $user->hasVerifiedEmail()) {
                $this->updateReminderStatus($note->id, 'failed');

                return null;
            }

            return [
                'email' => $user->email,
                'url' => route('notes.index', ['note' => $note->portable_id]),
            ];
        });

        if ($delivery === null) {
            return;
        }

        Mail::to($delivery['email'])->send(new NoteReminderMail($delivery['url']));

        DB::transaction(function (): void {
            $note = Note::withTrashed()->lockForUpdate()->find($this->noteId);

            if (
                $note !== null
                && $note->reminder_generation === $this->generation
                && $note->reminder_status === 'queued'
            ) {
                $this->clearReminder($note->id);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $note = Note::withTrashed()->lockForUpdate()->find($this->noteId);

            if ($note === null || $note->reminder_generation !== $this->generation) {
                return;
            }

            if ($note->trashed()) {
                $this->clearReminder($note->id);

                return;
            }

            if ($note->reminder_status === 'queued') {
                $this->updateReminderStatus($note->id, 'failed');
            }
        });
    }

    private function clearReminder(int $noteId): void
    {
        DB::table('notes')->where('id', $noteId)->update([
            'reminder_at' => null,
            'reminder_status' => null,
            'reminder_generation' => null,
        ]);
    }

    private function updateReminderStatus(int $noteId, string $status): void
    {
        DB::table('notes')->where('id', $noteId)->update([
            'reminder_status' => $status,
        ]);
    }
}
