<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id */
#[Fillable(['user_id', 'entity_type', 'entity_portable_id', 'action', 'client_change_id', 'conflict_note_portable_id', 'status', 'error', 'changed_at'])]
class SyncChange extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(int $userId, string $entityType, string $entityPortableId, string $action, ?string $clientChangeId = null): self
    {
        return static::create([
            'user_id' => $userId,
            'entity_type' => $entityType,
            'entity_portable_id' => $entityPortableId,
            'action' => $action,
            'client_change_id' => $clientChangeId,
            'changed_at' => now(),
        ]);
    }
}
