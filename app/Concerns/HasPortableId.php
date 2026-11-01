<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasPortableId
{
    protected static function bootHasPortableId(): void
    {
        static::creating(function (Model $model): void {
            if (! $model->getAttribute('portable_id')) {
                $model->setAttribute('portable_id', (string) Str::uuid());
            }
        });
    }
}
