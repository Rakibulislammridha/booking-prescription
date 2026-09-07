<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Reconcile;

/**
 * The (table, column) → catalog entity list catalog:reconcile scans in every tenant schema (CATALOG.md §6). The
 * Catalog module registers custom_brands; Prescription / Patients register prescription_items, patient_allergies …
 * from their service providers. Tables missing from a schema are skipped, so registration order never matters.
 */
final class SoftReferenceRegistry
{
    /** @var array<string, array{table: string, column: string, entity: string, snapshot_column: string|null, brand_column: string|null, generic_column: string|null}> */
    private array $refs = [];

    public function __construct()
    {
        $this->register('custom_brands', 'generic_id', 'generics', snapshotColumn: 'generic_name');
    }

    /**
     * @param  'generics'|'brands'|'strengths'|'allergy_classes'|'icd10_codes'  $entity
     * @param  string|null  $snapshotColumn  text snapshot of the referenced name (renamed detection)
     * @param  string|null  $brandColumn  for strength_id columns: the sibling brand column (triple_mismatch)
     * @param  string|null  $genericColumn  for strength_id / brand_id columns: the sibling generic column
     */
    public function register(string $table, string $column, string $entity, ?string $snapshotColumn = null, ?string $brandColumn = null, ?string $genericColumn = null): void
    {
        $this->refs["{$table}.{$column}"] = [
            'table' => $table, 'column' => $column, 'entity' => $entity,
            'snapshot_column' => $snapshotColumn, 'brand_column' => $brandColumn, 'generic_column' => $genericColumn,
        ];
    }

    /** @return list<array{table: string, column: string, entity: string, snapshot_column: string|null, brand_column: string|null, generic_column: string|null}> */
    public function all(): array
    {
        return array_values($this->refs);
    }
}
