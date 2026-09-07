<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Domain\Prescription\Data\DrugRef;
use App\Domain\Prescription\Data\ParsedLine;
use App\Domain\Prescription\Data\ResolvedItem;
use App\Domain\Prescription\Exceptions\DraftParseFailed;
use App\Domain\Prescription\Shorthand\ShorthandParser;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionItem;

/**
 * Resolves the writer's `items[]` (drug ref + shorthand) into ResolvedItems: DrugRef from the catalog / custom brand,
 * ParseContext from it, the authoritative server parse. Used by SaveDraft, the check endpoint and IssuePrescription.
 */
final class ItemResolver
{
    public function __construct(private readonly DrugRefResolver $drugs, private readonly ShorthandParser $parser) {}

    /**
     * @param  list<array<string, mixed>>  $items  writer rows: key, id?, sort_order, drug{…}|null, shorthand, safety_overrides[]
     * @param  array<int, PrescriptionItem>  $existing  the draft's current rows by id (for stored overrides / drug fallback)
     * @return list<ResolvedItem>
     */
    public function resolve(array $items, array $existing, int $contDays, string $locale): array
    {
        $resolved = [];

        foreach ($items as $i => $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;
            $current = $id !== null ? ($existing[$id] ?? null) : null;
            $drug = $this->drugFor($row, $current);
            $ctx = $drug !== null && $drug->resolved ? $drug->parseContext($contDays, $locale) : null;
            $shorthand = (string) ($row['shorthand'] ?? '');
            $parsed = $this->parser->parse($shorthand, $ctx);

            if ($drug !== null && ! $drug->resolved) {
                $parsed = $this->markUnresolved($parsed, $drug);
            }

            $resolved[] = new ResolvedItem(
                key: (string) ($row['key'] ?? ($current?->clientKey() ?? 'i'.$i)),
                id: $current?->id,
                sortOrder: isset($row['sort_order']) ? (int) $row['sort_order'] : $i,
                drug: $drug,
                shorthand: $shorthand,
                parsed: $parsed,
                overrideRequests: array_values(array_map(fn ($o) => ['fingerprint' => (string) ($o['fingerprint'] ?? ''), 'reason' => (string) ($o['reason'] ?? '')], (array) ($row['safety_overrides'] ?? []))),
                existingOverrides: $current !== null ? $current->safety_overrides : [],
                instructionBn: isset($row['instruction_bn']) ? (string) $row['instruction_bn'] : null,
            );
        }

        return $resolved;
    }

    /**
     * Re-resolve a draft's stored rows (issue-time snapshot refresh).
     *
     * @return list<ResolvedItem>
     */
    public function fromRows(Prescription $rx, int $contDays, string $locale): array
    {
        $rows = [];

        foreach ($rx->items as $item) {
            $rows[] = [
                'key' => $item->clientKey(), 'id' => $item->id, 'sort_order' => $item->sort_order,
                'drug' => ['generic_id' => $item->generic_id, 'brand_id' => $item->brand_id, 'custom_brand_id' => $item->custom_brand_id, 'strength_id' => $item->strength_id],
                'shorthand' => (string) ($item->dose_json['normalized'] ?? $item->dose_json['raw'] ?? ''),
                'safety_overrides' => array_map(fn ($o) => ['fingerprint' => $o['fingerprint'] ?? '', 'reason' => $o['reason'] ?? ''], $item->safety_overrides),
                'instruction_bn' => $item->instruction_bn,
            ];
        }

        return $this->resolve($rows, $rx->items->keyBy('id')->all(), $contDays, $locale);
    }

    /** @param  list<ResolvedItem>  $items */
    public function assertNoErrors(array $items): void
    {
        $errors = [];

        foreach ($items as $item) {
            if ($item->parsed->hasErrors()) {
                $errors['items.'.$item->key] = array_map(fn ($i) => $i->toArray(), $item->parsed->errors());
            }
        }

        if ($errors !== []) {
            throw new DraftParseFailed($errors);
        }
    }

    /** @param  array<string, mixed>  $row */
    private function drugFor(array $row, ?PrescriptionItem $current): ?DrugRef
    {
        $ref = isset($row['drug']) && is_array($row['drug']) ? $row['drug'] : null;

        if ($ref === null && $current !== null && ($current->generic_id !== null || $current->custom_brand_id !== null)) {
            $ref = ['generic_id' => $current->generic_id, 'brand_id' => $current->brand_id, 'custom_brand_id' => $current->custom_brand_id, 'strength_id' => $current->strength_id];
        }

        if ($ref === null || (empty($ref['generic_id']) && empty($ref['custom_brand_id']) && empty($ref['strength_id']))) {
            return null;
        }

        return $this->drugs->resolve($ref);
    }

    private function markUnresolved(ParsedLine $parsed, DrugRef $drug): ParsedLine
    {
        // The line keeps its parse; the catalog / custom-brand checks raise the (non-overridable) alert.
        return $parsed;
    }
}
