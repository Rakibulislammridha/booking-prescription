<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Domain\Catalog\Enums\CatalogJobKind;
use App\Domain\Catalog\Enums\CatalogJobStatus;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of catalog maintenance the console asked Horizon to do (`public.catalog_jobs`): a bundle upload and its
 * dry run / apply, a search-index rebuild, a reconciliation sweep. Progress and the result live here so the console
 * can show them without a request ever running an import itself (CATALOG.md §5).
 *
 * @property int $id
 * @property string $public_id
 * @property CatalogJobKind $kind
 * @property string|null $mode
 * @property CatalogJobStatus $status
 * @property string|null $source
 * @property string|null $version
 * @property string|null $release_ref
 * @property bool $full
 * @property string|null $bundle_path
 * @property array<int, string> $bundle_files
 * @property string|null $checksum
 * @property array<string, mixed> $progress
 * @property array<string, mixed>|null $report
 * @property string|null $error
 * @property int|null $catalog_version_id
 * @property int|null $requested_by_super_admin_id
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SuperAdmin|null $requestedBy
 */
final class CatalogJob extends CentralModel
{
    use HasPublicId;

    protected $table = 'public.catalog_jobs';

    protected $fillable = [
        'kind', 'mode', 'status', 'source', 'version', 'release_ref', 'full', 'bundle_path', 'bundle_files', 'checksum',
        'progress', 'report', 'error', 'catalog_version_id', 'requested_by_super_admin_id', 'queued_at', 'started_at', 'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => CatalogJobKind::class,
            'status' => CatalogJobStatus::class,
            'full' => 'boolean',
            'bundle_files' => 'array',
            'progress' => 'array',
            'report' => 'array',
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeOfKind(Builder $query, CatalogJobKind $kind): void
    {
        $query->where('kind', $kind->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeUnfinished(Builder $query): void
    {
        $query->whereIn('status', [CatalogJobStatus::Queued->value, CatalogJobStatus::Running->value]);
    }

    /** @return BelongsTo<SuperAdmin, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'requested_by_super_admin_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [CatalogJobStatus::Succeeded, CatalogJobStatus::Failed], true);
    }
}
