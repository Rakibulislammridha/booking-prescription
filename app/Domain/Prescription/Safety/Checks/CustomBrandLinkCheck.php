<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety\Checks;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Prescription\Enums\SafetySeverity as Severity;
use App\Domain\Prescription\Safety\SafetyAlert;
use App\Domain\Prescription\Safety\SafetyCheck;
use App\Domain\Prescription\Safety\SafetyContext;

/**
 * I4: a custom brand that cannot be resolved to a live catalog generic is unusable — row exists in the tenant, not
 * soft-deleted, is_active, review_status ≠ rejected, its generic exists and is active, and item.generic_id equals it.
 * Failure → critical `custom_brand.unlinked`, non-overridable.
 */
final class CustomBrandLinkCheck implements SafetyCheck
{
    public function __construct(private readonly CatalogCache $catalog) {}

    public function key(): string
    {
        return 'custom_brand';
    }

    /** @return list<SafetyAlert> */
    public function run(SafetyContext $ctx): array
    {
        $alerts = [];

        foreach ($ctx->items as $item) {
            if ($item->customBrandId === null) {
                continue;
            }

            $row = $item->customBrand;
            $reason = match (true) {
                $row === null || ! ($row['exists'] ?? false) => 'missing',
                (bool) ($row['deleted'] ?? false) => 'deleted',
                ! (bool) ($row['is_active'] ?? false) => 'inactive',
                ($row['review_status'] ?? null) === 'rejected' => 'rejected',
                ! isset($row['generic_id']) => 'no_generic',
                $item->genericId !== (int) $row['generic_id'] => 'generic_mismatch',
                default => null,
            };

            if ($reason === null) {
                $generic = $this->catalog->generic((int) $row['generic_id']);
                $reason = $generic === null ? 'generic_missing' : (! $generic['is_active'] ? 'generic_inactive' : null);
            }

            if ($reason === null) {
                continue;
            }

            $alerts[] = new SafetyAlert(
                key: $this->key(), code: 'custom_brand.unlinked', severity: Severity::Critical,
                fingerprint: "custom_brand:unlinked:c{$item->customBrandId}", overridable: false,
                title: 'Custom brand not linked to a catalog generic',
                message: "{$item->displayName()}: this clinic brand has no usable molecule link ({$reason}). It cannot be prescribed until it is linked.",
                messageBn: "{$item->displayName()}: এই ক্লিনিক ব্র্যান্ডের কোনো ব্যবহারযোগ্য মলিকিউল লিংক নেই ({$reason})। লিংক না হওয়া পর্যন্ত এটি প্রেসক্রাইব করা যাবে না।",
                itemKeys: [$item->key], genericIds: $item->genericId !== null ? [$item->genericId] : [],
                evidence: ['custom_brand_id' => $item->customBrandId, 'reason' => $reason],
            );
        }

        return $alerts;
    }
}
