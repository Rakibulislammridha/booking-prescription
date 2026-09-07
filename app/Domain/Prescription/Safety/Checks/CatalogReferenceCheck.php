<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety\Checks;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Prescription\Data\SafetyItem;
use App\Domain\Prescription\Enums\SafetySeverity as Severity;
use App\Domain\Prescription\Safety\SafetyAlert;
use App\Domain\Prescription\Safety\SafetyCheck;
use App\Domain\Prescription\Safety\SafetyContext;

/**
 * generic_id / brand_id / strength_id resolve via CatalogCache and are active; strength belongs to brand belongs to
 * generic. Missing / mismatched → critical `catalog.ref_missing` (non-overridable); inactive → warning
 * `catalog.discontinued`. Custom-brand items validate only their generic here (the brand row is CustomBrandLinkCheck's).
 */
final class CatalogReferenceCheck implements SafetyCheck
{
    public function __construct(private readonly CatalogCache $catalog) {}

    public function key(): string
    {
        return 'catalog';
    }

    /** @return list<SafetyAlert> */
    public function run(SafetyContext $ctx): array
    {
        $alerts = [];

        foreach ($ctx->items as $item) {
            if ($item->genericId === null) {
                continue;                                             // drug-less line: parse.error/drug_missing handles it
            }

            $generic = $this->catalog->generic($item->genericId);

            if ($generic === null) {
                $alerts[] = $this->missing($item, 'generic', $item->genericId);

                continue;
            }

            if (! $generic['is_active']) {
                $alerts[] = $this->discontinued($item, 'generic', (string) $generic['name']);
            }

            if ($item->brandId !== null) {
                $brand = $this->catalog->brand($item->brandId);

                if ($brand === null || (int) $brand['generic_id'] !== $item->genericId) {
                    $alerts[] = $this->missing($item, 'brand', $item->brandId);

                    continue;
                }

                if (! $brand['is_active']) {
                    $alerts[] = $this->discontinued($item, 'brand', (string) $brand['name']);
                }
            }

            if ($item->strengthId !== null) {
                $strength = $this->catalog->strength($item->strengthId);

                if ($strength === null || (int) $strength['generic_id'] !== $item->genericId || ($item->brandId !== null && (int) $strength['brand_id'] !== $item->brandId)) {
                    $alerts[] = $this->missing($item, 'strength', $item->strengthId);

                    continue;
                }

                if (! $strength['is_active']) {
                    $alerts[] = $this->discontinued($item, 'strength', (string) $strength['strength_label']);
                }
            }
        }

        return $alerts;
    }

    private function missing(SafetyItem $item, string $entity, int $id): SafetyAlert
    {
        return new SafetyAlert(
            key: $this->key(), code: 'catalog.ref_missing', severity: Severity::Critical,
            fingerprint: "catalog:ref_missing:{$item->genericId}:{$entity}{$id}", overridable: false,
            title: 'Catalog reference missing',
            message: "{$item->displayName()}: the {$entity} #{$id} is no longer in the drug catalog. Re-pick the drug.",
            messageBn: "{$item->displayName()}: {$entity} #{$id} ওষুধ তালিকায় নেই। ওষুধটি আবার বাছাই করুন।",
            itemKeys: [$item->key], genericIds: [$item->genericId], evidence: ['entity' => $entity, 'id' => $id],
        );
    }

    private function discontinued(SafetyItem $item, string $entity, string $name): SafetyAlert
    {
        return new SafetyAlert(
            key: $this->key(), code: 'catalog.discontinued', severity: Severity::Warning,
            fingerprint: "catalog:discontinued:{$item->genericId}:{$entity}", overridable: true,
            title: 'Discontinued presentation',
            message: "{$name} is marked discontinued in the catalog.",
            messageBn: "{$name} ওষুধ তালিকায় বন্ধ হিসেবে চিহ্নিত।",
            itemKeys: [$item->key], genericIds: [$item->genericId], evidence: ['entity' => $entity, 'name' => $name],
        );
    }
}
