<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Reconcile;

use App\Domain\Catalog\Enums\ReconciliationStatus;
use App\Domain\Catalog\Services\CatalogCache;
use App\Models\Central\CatalogReconciliationReport;
use App\Models\Tenant\CustomBrand;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scans the current tenant's soft references (CATALOG.md §6 steps 2–4) and writes one
 * public.catalog_reconciliation_reports row per registered (table, column). Never touches clinical rows; the only
 * side effect is deactivating custom brands whose generic vanished (they leave autocomplete).
 */
final class ReferenceScanner
{
    /** @var array<string, array<int|string, bool>> entity → id → is_active */
    private array $ids = [];

    /** @var array<string, array<int, string>> generic id → live name */
    private array $names = [];

    public function __construct(private readonly SoftReferenceRegistry $registry, private readonly CatalogCache $cache) {}

    /**
     * @return array{orphans: int, inactive: int, reports: int}
     */
    public function scanCurrentTenant(string $runId): array
    {
        $tenantId = Tenancy::id() ?? throw new \LogicException('ReferenceScanner needs an active tenant');
        $versionRow = $this->cache->currentVersionRow();
        $versionId = $versionRow === null ? null : (int) $versionRow['id'];
        $version = $versionRow === null ? 'v0' : (string) $versionRow['version'];
        $sample = (int) config('catalog.reconcile.sample_size', 50);
        $totals = ['orphans' => 0, 'inactive' => 0, 'reports' => 0];

        foreach ($this->registry->all() as $ref) {
            if (! Schema::hasTable($ref['table']) || ! Schema::hasColumn($ref['table'], $ref['column'])) {
                continue;
            }

            $this->loadEntity($ref['entity']);
            $details = [];
            $sampleIds = [];
            $checked = 0;
            $orphans = 0;
            $inactive = 0;
            $renamed = 0;

            $groups = DB::table($ref['table'])->whereNotNull($ref['column'])
                ->selectRaw("{$ref['column']} AS ref, count(*) AS c, min(id) AS min_id, max(id) AS max_id")
                ->groupBy($ref['column'])->orderBy($ref['column'])->cursor();

            foreach ($groups as $g) {
                $refId = $ref['entity'] === 'icd10_codes' ? (string) $g->ref : (int) $g->ref;
                $count = (int) $g->c;
                $checked += $count;

                if (! array_key_exists($refId, $this->ids[$ref['entity']])) {
                    $orphans += $count;
                    $details['orphan'][(string) $refId] = $count;

                    if (count($sampleIds) < $sample) {
                        $sampleIds[] = (int) $g->min_id;
                    }
                } elseif (! $this->ids[$ref['entity']][$refId]) {
                    $inactive += $count;
                    $details['inactive'][(string) $refId] = $count;
                }
            }

            if ($ref['snapshot_column'] !== null && $ref['entity'] === 'generics' && Schema::hasColumn($ref['table'], $ref['snapshot_column'])) {
                $renamedGroups = DB::table($ref['table'])->whereNotNull($ref['column'])
                    ->selectRaw("{$ref['column']} AS ref, {$ref['snapshot_column']} AS snap, count(*) AS c")
                    ->groupBy($ref['column'], $ref['snapshot_column'])->cursor();

                foreach ($renamedGroups as $g) {
                    $live = $this->names['generics'][(int) $g->ref] ?? null;

                    if ($live !== null && $live !== (string) $g->snap) {
                        $renamed += (int) $g->c;
                        $details['renamed'][(string) $g->ref] = ($details['renamed'][(string) $g->ref] ?? 0) + (int) $g->c;
                    }
                }
            }

            if ($ref['brand_column'] !== null && $ref['entity'] === 'strengths' && Schema::hasColumn($ref['table'], $ref['brand_column'])) {
                $this->loadEntity('strengths');

                foreach (DB::table($ref['table'])->whereNotNull($ref['column'])->whereNotNull($ref['brand_column'])
                    ->selectRaw("{$ref['column']} AS ref, {$ref['brand_column']} AS brand, count(*) AS c")->groupBy($ref['column'], $ref['brand_column'])->cursor() as $g) {
                    $parent = $this->strengthParents[(int) $g->ref] ?? null;

                    if ($parent !== null && $parent['brand_id'] !== (int) $g->brand) {
                        $details['triple_mismatch'][(string) $g->ref] = (int) $g->c;
                    }
                }
            }

            $status = match (true) {
                $orphans > 0 => ReconciliationStatus::OrphansFound,
                $inactive > 0 => ReconciliationStatus::InactiveFound,
                $renamed > 0 || isset($details['triple_mismatch']) => ReconciliationStatus::RenamedFound,
                default => ReconciliationStatus::Clean,
            };

            CatalogReconciliationReport::query()->create([
                'run_id' => $runId, 'tenant_id' => $tenantId, 'catalog_version_id' => $versionId,
                'table_name' => $ref['table'], 'column_name' => $ref['column'], 'checked_count' => $checked, 'orphan_count' => $orphans,
                'sample_ids' => $sampleIds, 'details' => $details === [] ? (object) [] : $details, 'status' => $status->value,
            ]);

            $totals['orphans'] += $orphans;
            $totals['inactive'] += $inactive;
            $totals['reports']++;

            if ($ref['table'] === 'custom_brands' && $ref['column'] === 'generic_id') {
                $this->deactivateOrphanCustomBrands(array_keys($details['orphan'] ?? []), array_keys($details['inactive'] ?? []), $version);
                $this->refreshRenamedCustomBrands(array_keys($details['renamed'] ?? []));
            }
        }

        return $totals;
    }

    /** @var array<int, array{brand_id: int, generic_id: int}> */
    private array $strengthParents = [];

    private function loadEntity(string $entity): void
    {
        if (isset($this->ids[$entity])) {
            return;
        }

        $keyColumn = $entity === 'icd10_codes' ? 'code' : 'id';
        $columns = $entity === 'generics' ? ['id', 'is_active', 'name'] : ($entity === 'strengths' ? ['id', 'is_active', 'brand_id', 'generic_id'] : [$keyColumn, 'is_active']);
        $this->ids[$entity] = [];

        foreach (DB::connection('catalog')->table($entity)->select($columns)->cursor() as $row) {
            $key = $entity === 'icd10_codes' ? (string) $row->code : (int) $row->id;
            $this->ids[$entity][$key] = (bool) $row->is_active;

            if ($entity === 'generics') {
                $this->names['generics'][(int) $row->id] = (string) $row->name;
            }

            if ($entity === 'strengths') {
                $this->strengthParents[(int) $row->id] = ['brand_id' => (int) $row->brand_id, 'generic_id' => (int) $row->generic_id];
            }
        }
    }

    /**
     * The one permitted side effect (CATALOG.md §6.4): a custom brand whose generic vanished or was discontinued leaves
     * autocomplete; CustomBrandLinkCheck blocks any draft still holding it.
     *
     * @param  list<int|string>  $orphanGenericIds
     * @param  list<int|string>  $inactiveGenericIds
     */
    private function deactivateOrphanCustomBrands(array $orphanGenericIds, array $inactiveGenericIds, string $version): void
    {
        $ids = array_map('intval', [...$orphanGenericIds, ...$inactiveGenericIds]);

        if ($ids === []) {
            return;
        }

        CustomBrand::query()->whereIn('generic_id', $ids)->where('is_active', true)->each(function (CustomBrand $brand) use ($version): void {
            $brand->forceFill(['is_active' => false, 'review_note' => "generic missing in catalog {$version}"])->save();   // unsearchable() via the Scout observer
        });
    }

    /** @param  list<int|string>  $genericIds */
    private function refreshRenamedCustomBrands(array $genericIds): void
    {
        if ($genericIds === []) {
            return;
        }

        CustomBrand::query()->whereIn('generic_id', array_map('intval', $genericIds))->usable()->each(fn (CustomBrand $brand) => $brand->searchable());
    }
}
