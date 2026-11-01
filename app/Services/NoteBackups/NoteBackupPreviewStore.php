<?php

namespace App\Services\NoteBackups;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class NoteBackupPreviewStore
{
    public function stage(int $userId, UploadedFile $archive): string
    {
        $token = bin2hex(random_bytes(32));
        $path = $this->pathFor($userId, $token);
        $storedPath = $archive->storeAs(dirname($path), basename($path), 'local');

        if ($storedPath === false) {
            throw new NoteBackupException('The backup could not be staged for preview.', 500, 'storage_error');
        }

        return $token;
    }

    public function resolve(int $userId, string $token): string
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new NoteBackupException('The backup preview is missing or expired.', 400, 'preview_expired');
        }

        $path = $this->pathFor($userId, $token);
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            throw new NoteBackupException('The backup preview is missing or expired.', 400, 'preview_expired');
        }

        $expiresAt = Carbon::createFromTimestamp(
            $disk->lastModified($path),
            'UTC',
        )->addMinutes((int) config('import_export.preview_ttl_minutes'));

        if ($expiresAt->isPast()) {
            $this->deletePath($path);

            throw new NoteBackupException('The backup preview has expired. Upload the archive again.', 400, 'preview_expired');
        }

        return $disk->path($path);
    }

    public function expiresAt(int $userId, string $token): string
    {
        $path = $this->pathFor($userId, $token);
        $timestamp = Storage::disk('local')->lastModified($path);

        return Carbon::createFromTimestamp($timestamp, 'UTC')
            ->addMinutes((int) config('import_export.preview_ttl_minutes'))
            ->toIso8601String();
    }

    public function delete(int $userId, string $token): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return;
        }

        $this->deletePath($this->pathFor($userId, $token));
    }

    public function cleanupExpired(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subMinutes((int) config('import_export.preview_ttl_minutes'))->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles('note-backups/previews') as $path) {
            if ($disk->lastModified($path) >= $cutoff) {
                continue;
            }

            if (! $disk->delete($path)) {
                Log::warning('An expired note backup preview could not be deleted.', ['path' => $path]);

                continue;
            }

            $deleted++;
        }

        return $deleted;
    }

    public function cleanupOrphanedWorkspaces(): int
    {
        $root = storage_path('app/private/note-backup-work');
        $cutoff = now()->subDay()->getTimestamp();
        $deleted = 0;

        if (! is_dir($root)) {
            return 0;
        }

        foreach (File::directories($root) as $directory) {
            if (File::lastModified($directory) >= $cutoff) {
                continue;
            }

            if (! File::deleteDirectory($directory)) {
                Log::warning('An orphaned note backup workspace could not be deleted.', ['path' => $directory]);

                continue;
            }

            $deleted++;
        }

        return $deleted;
    }

    private function pathFor(int $userId, string $token): string
    {
        return 'note-backups/previews/'.$userId.'/'.hash('sha256', $token).'.zip';
    }

    private function deletePath(string $path): void
    {
        $disk = Storage::disk('local');

        if ($disk->exists($path) && ! $disk->delete($path)) {
            Log::warning('A note backup preview could not be deleted.', ['path' => $path]);
        }
    }
}
