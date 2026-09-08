<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Domain\Catalog\Enums\CatalogVersionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DGDA release tracking; every import runs under one version. No catalog_version_id / is_active of its own (SCHEMA §4).
 *
 * @property int $id
 * @property string $version
 * @property string|null $dgda_release_ref
 * @property CatalogVersionStatus $status
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $applied_at
 * @property array<string, array<string, int>> $row_counts
 * @property string|null $checksum_sha256
 * @property string|null $notes
 * @property string|null $applied_by
 */
final class CatalogVersion extends CatalogModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => CatalogVersionStatus::class, 'released_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime', 'row_counts' => 'array'];
    }

    /**
     * "Current" = the latest applied row.
     *
     * @param  Builder<CatalogVersion>  $query
     */
    public function scopeApplied(Builder $query): void
    {
        $query->where('status', CatalogVersionStatus::Applied->value)->orderByDesc('applied_at')->orderByDesc('id');
    }

    /** @return HasMany<CatalogImportIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(CatalogImportIssue::class);
    }
}
