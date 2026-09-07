<?php

declare(strict_types=1);

namespace App\Models\Central;

use Carbon\CarbonImmutable;
use Database\Factories\Central\CatalogReconciliationReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nightly soft-reference scan output (SCHEMA §2.12). created_at only. status values are App\Domain\Catalog\Enums\ReconciliationStatus.
 * TODO(catalog): cast `status` to App\Domain\Catalog\Enums\ReconciliationStatus once the Catalog module ships the enum.
 *
 * @property int $id
 * @property string $run_id
 * @property int $tenant_id
 * @property int|null $catalog_version_id
 * @property string $table_name
 * @property string $column_name
 * @property int $checked_count
 * @property int $orphan_count
 * @property array<int, int|string> $sample_ids
 * @property array<string, mixed>|null $details
 * @property string $status
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property int|null $resolved_by_super_admin_id
 * @property-read SuperAdmin|null $resolvedBy
 */
final class CatalogReconciliationReport extends CentralModel
{
    /** @use HasFactory<CatalogReconciliationReportFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static string $factory = CatalogReconciliationReportFactory::class;

    protected $table = 'public.catalog_reconciliation_reports';

    protected $fillable = [
        'run_id', 'tenant_id', 'catalog_version_id', 'table_name', 'column_name', 'checked_count', 'orphan_count',
        'sample_ids', 'details', 'status', 'resolved_at', 'resolved_by_super_admin_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'checked_count' => 'integer',
            'orphan_count' => 'integer',
            'sample_ids' => 'array',
            'details' => 'array',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<SuperAdmin, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'resolved_by_super_admin_id');
    }
}
