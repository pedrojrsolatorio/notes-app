<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\NoteBackups\NoteBackupException;
use App\Services\NoteBackups\NoteBackupPreviewStore;
use App\Services\NoteBackups\NoteBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class NoteBackupController extends Controller
{
    public function export(Request $request, NoteBackupService $backupService): BinaryFileResponse|JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $throttled = $this->throttle($user);

        if ($throttled !== null) {
            return $throttled;
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed'],
        ]);

        try {
            $archivePath = $backupService->export($user, $validated['password']);

            return response()->download($archivePath, 'notes-backup.zip')->deleteFileAfterSend(true);
        } catch (NoteBackupException $exception) {
            return $this->failure($exception);
        }
    }

    public function preview(Request $request, NoteBackupService $backupService): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $throttled = $this->throttle($user);

        if ($throttled !== null) {
            return $throttled;
        }

        $validated = $request->validate([
            'archive' => ['required', 'file'],
            'password' => ['required', 'string'],
        ]);

        try {
            return response()->json($backupService->preview($user, $validated['archive'], $validated['password']));
        } catch (NoteBackupException $exception) {
            return $this->failure($exception);
        }
    }

    public function import(Request $request, NoteBackupService $backupService): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $throttled = $this->throttle($user);

        if ($throttled !== null) {
            return $throttled;
        }

        $validated = $request->validate([
            'preview_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'password' => ['required', 'string'],
        ]);

        try {
            return response()->json($backupService->restore(
                $user,
                $validated['preview_token'],
                $validated['password'],
            ));
        } catch (NoteBackupException $exception) {
            return $this->failure($exception);
        }
    }

    public function cancel(Request $request, NoteBackupPreviewStore $previewStore): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $validated = $request->validate([
            'preview_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);

        try {
            $previewStore->resolve((int) $user->getKey(), $validated['preview_token']);
            $previewStore->delete((int) $user->getKey(), $validated['preview_token']);

            return response()->json(['message' => 'The backup preview was cancelled.']);
        } catch (NoteBackupException $exception) {
            if ($exception->reasonCode === 'preview_expired') {
                return response()->json([
                    'message' => 'The backup preview is missing or expired.',
                    'code' => 'preview_expired',
                ], 404);
            }

            return $this->failure($exception);
        }
    }

    private function throttle(User $user): ?JsonResponse
    {
        $key = 'note-backups:'.$user->getAuthIdentifier();
        $attempts = RateLimiter::increment($key);

        if ($attempts <= config('import_export.rate_limit_per_minute')) {
            return null;
        }

        $retryAfter = RateLimiter::availableIn($key);

        return response()->json([
            'message' => "Too many backup attempts. Try again in {$retryAfter} seconds.",
            'retry_after' => $retryAfter,
        ], 429, ['Retry-After' => (string) $retryAfter]);
    }

    private function failure(NoteBackupException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->reasonCode,
            'items' => $exception->details,
        ], $exception->status);
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
