<?php

namespace App\Sync;

use App\Models\SyncChange;

class ChangeRecorder
{
    private static bool $disabled = false;

    public static function disable(): void
    {
        static::$disabled = true;
    }

    public static function enable(): void
    {
        static::$disabled = false;
    }

    public static function withoutRecording(callable $callback): mixed
    {
        $previous = static::$disabled;
        static::$disabled = true;

        try {
            return $callback();
        } finally {
            static::$disabled = $previous;
        }
    }

    public static function shouldRecord(): bool
    {
        return ! static::$disabled;
    }

    public static function record(int $userId, string $entityType, string $entityPortableId, string $action, ?string $clientChangeId = null): ?SyncChange
    {
        if (! static::$disabled) {
            return SyncChange::record($userId, $entityType, $entityPortableId, $action, $clientChangeId);
        }

        return null;
    }
}
