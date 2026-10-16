<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoteAttachment extends Model
{
    protected $fillable = [
        'note_id',
        'user_id',
        'original_name',
        'file_path',
        'mime_type',
        'file_size',
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
