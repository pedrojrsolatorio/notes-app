<?php

namespace App\Console\Commands;

use App\Jobs\SendNoteReminder;
use App\Models\Note;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LogicException;

#[Signature('app:dispatch-due-note-reminders')]
#[Description('Queue due note reminders for delivery')]
class DispatchDueNoteReminders extends Command
{
    public function handle(): int
    {
        Note::withTrashed()
            ->where('reminder_status', 'scheduled')
            ->whereNotNull('reminder_at')
            ->where('reminder_at', '<=', now('UTC'))
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($notes): void {
                foreach ($notes as $note) {
                    DB::transaction(function () use ($note): void {
                        $lockedNote = Note::withTrashed()->lockForUpdate()->find($note->id);

                        if (
                            $lockedNote === null
                            || $lockedNote->reminder_status !== 'scheduled'
                            || $lockedNote->reminder_at === null
                            || $lockedNote->reminder_at->isFuture()
                        ) {
                            return;
                        }

                        if ($lockedNote->reminder_generation === null) {
                            throw new LogicException('A scheduled note reminder has no generation token.');
                        }

                        if ($lockedNote->trashed()) {
                            DB::table('notes')->where('id', $lockedNote->id)->update([
                                'reminder_at' => null,
                                'reminder_status' => null,
                                'reminder_generation' => null,
                            ]);

                            return;
                        }

                        DB::table('notes')
                            ->where('id', $lockedNote->id)
                            ->where('reminder_status', 'scheduled')
                            ->where('reminder_generation', $lockedNote->reminder_generation)
                            ->update(['reminder_status' => 'queued']);

                        SendNoteReminder::dispatch($lockedNote->id, $lockedNote->reminder_generation)
                            ->beforeCommit();
                    });
                }
            });

        return self::SUCCESS;
    }
}
