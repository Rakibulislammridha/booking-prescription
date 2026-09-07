<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Fills public_id (char(26) ULID) on `creating` for tables that have the column (SCHEMA §0.2).
 * Never HasUuids. The bigint id stays internal; routes bind {model:public_id} explicitly.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (self $model): void {
            if (! $model->usesPublicId()) {
                return;
            }

            if (blank($model->getAttribute('public_id'))) {
                $model->setAttribute('public_id', (string) Str::ulid());
            }
        });
    }

    public function usesPublicId(): bool
    {
        return true;
    }

    /** @param  Builder<static>  $query */
    public function scopeWherePublicId($query, string $publicId): void
    {
        $query->where($this->qualifyColumn('public_id'), $publicId);
    }
}
