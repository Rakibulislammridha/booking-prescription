<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Domain\Catalog\Enums\CatalogImportIssueKind;
use App\Models\Catalog\Concerns\EnforcesCatalogReadOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rows the importer could not map cleanly (SCHEMA §4). No is_active column.
 *
 * @property int $id
 * @property int $catalog_version_id
 * @property CatalogImportIssueKind $kind
 * @property int|null $source_row
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolved_by
 * @property array<string, mixed>|null $resolution
 */
final class CatalogImportIssue extends CatalogModel
{
    use EnforcesCatalogReadOnly;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => CatalogImportIssueKind::class, 'source_row' => 'integer', 'payload' => 'array', 'resolved_at' => 'immutable_datetime', 'resolution' => 'array'];
    }

    /** @param  Builder<CatalogImportIssue>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /** @return BelongsTo<CatalogVersion, $this> */
    public function catalogVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogVersion::class);
    }
}
