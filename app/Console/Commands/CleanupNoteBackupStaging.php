<?php

namespace App\Console\Commands;

use App\Services\NoteBackups\NoteBackupPreviewStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:cleanup-note-backup-staging')]
#[Description('Remove expired backup previews and orphaned backup workspaces.')]
class CleanupNoteBackupStaging extends Command
{
    public function handle(NoteBackupPreviewStore $previewStore): int
    {
        $previews = $previewStore->cleanupExpired();
        $workspaces = $previewStore->cleanupOrphanedWorkspaces();

        $this->info("Removed {$previews} expired previews and {$workspaces} orphaned workspaces.");

        return self::SUCCESS;
    }
}
