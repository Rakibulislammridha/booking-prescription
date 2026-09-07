<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\CustomBrandPromotionStatus;
use App\Domain\Catalog\Enums\CustomBrandReviewStatus;
use App\Domain\Catalog\Exceptions\CustomBrandNotReviewable;
use App\Models\Central\CustomBrandPromotion;
use App\Models\Central\Tenant;
use App\Models\Tenant\CustomBrand;
use App\Tenancy\Facades\Tenancy;

/** POST …/promotions/{promotion}/reject {reason}: the brand stays usable in that tenant but leaves the queue (CATALOG.md §8). */
final class RejectCustomBrandPromotion
{
    public function handle(CustomBrandPromotion $promotion, string $reason, int $superAdminId): void
    {
        if ($promotion->status !== CustomBrandPromotionStatus::Pending->value) {
            throw new CustomBrandNotReviewable($promotion->status);
        }

        $promotion->forceFill([
            'status' => CustomBrandPromotionStatus::Rejected->value,
            'reviewed_by_super_admin_id' => $superAdminId,
            'reviewed_at' => now(),
            'decision' => ['mode' => null, 'brand_id' => null, 'strength_id' => null, 'catalog_version_id' => null, 'reason' => $reason],
        ])->save();

        $tenant = Tenant::query()->find($promotion->tenant_id);

        if ($tenant === null) {
            return;
        }

        Tenancy::run($tenant, function () use ($promotion, $reason): void {
            CustomBrand::query()->find($promotion->custom_brand_id)?->forceFill([
                'review_status' => CustomBrandReviewStatus::Rejected, 'reviewed_at' => now(), 'review_note' => mb_substr($reason, 0, 255),
            ])->save();
        });
    }
}
