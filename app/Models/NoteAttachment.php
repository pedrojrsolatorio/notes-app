<?php

namespace App\Models;

use App\Concerns\HasPortableId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $portable_id */
class NoteAttachment extends Model
{
    use HasPortableId;

    protected $fillable = [
        'note_id',
        'user_id',
        'original_name',
        'file_path',
        'mime_type',
        'file_size',
        'portable_id',
    ];

    /** @return BelongsTo<Note, $this> */
    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
