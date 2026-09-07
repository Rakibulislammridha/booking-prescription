<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Models\Central\CatalogReconciliationReport;
use App\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CatalogReconciliationReport> */
final class CatalogReconciliationReportFactory extends Factory
{
    protected $model = CatalogReconciliationReport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'run_id' => (string) Str::ulid(),
            'tenant_id' => Tenant::factory(),
            'table_name' => 'prescription_items',
            'column_name' => 'generic_id',
            'checked_count' => 0,
            'orphan_count' => 0,
            'sample_ids' => [],
            'details' => [],
            'status' => 'clean',
        ];
    }
}
