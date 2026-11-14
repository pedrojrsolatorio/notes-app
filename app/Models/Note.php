<?php

namespace App\Models;

use App\Concerns\HasPortableId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property string $portable_id */
/**
 * @property CarbonImmutable|null $reminder_at
 * @property string $portable_id
 * @property string|null $reminder_status
 * @property string|null $reminder_generation
 */
class Note extends Model
{
    use HasPortableId;
    use SoftDeletes;

    protected $fillable = [
        'folder_id',
        'title',
        'content',
        'portable_id',
    ];

    protected $attributes = [
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'reminder_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Folder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /** @return HasMany<NoteAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(NoteAttachment::class);
    }
}
