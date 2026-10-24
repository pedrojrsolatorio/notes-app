<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folder extends Model
{
    protected $fillable = ['name'];

    public static function normalizeName(string $name): string
    {
        $normalizedName = preg_replace(
            '/\A[\p{Z}\x{0009}-\x{000D}\x{0085}]+|[\p{Z}\x{0009}-\x{000D}\x{0085}]+\z/u',
            '',
            $name
        );

        if ($normalizedName === null) {
            throw new \InvalidArgumentException('The folder name must be valid UTF-8.');
        }

        return $normalizedName;
    }

    public static function nameKeyFor(string $name): string
    {
        return mb_strtolower(self::normalizeName($name), 'UTF-8');
    }

    public function setNameAttribute(string $name): void
    {
        $normalizedName = self::normalizeName($name);

        $this->attributes['name'] = $normalizedName;
        $this->attributes['name_key'] = mb_strtolower($normalizedName, 'UTF-8');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Note, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }
}
