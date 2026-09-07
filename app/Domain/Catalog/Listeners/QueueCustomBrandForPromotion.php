<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Listeners;

use App\Domain\Catalog\Enums\CustomBrandPromotionStatus;
use App\Domain\Catalog\Events\CustomBrandCreated;
use App\Models\Central\CustomBrandPromotion;

/**
 * Writes the cross-tenant review-queue row (SCHEMA §2.18) so super admins never scan tenant schemas. Idempotent on
 * (tenant_id, custom_brand_id): a resubmission after rejection reopens the same row.
 */
final class QueueCustomBrandForPromotion
{
    public function handle(CustomBrandCreated $event): void
    {
        $s = $event->snapshot;

        CustomBrandPromotion::query()->updateOrCreate(
            ['tenant_id' => $event->tenantId, 'custom_brand_id' => $event->customBrandId],
            [
                'brand_name' => (string) $s['brand_name'],
                'manufacturer' => $s['manufacturer'] ?? null,
                'generic_id' => (int) $s['generic_id'],
                'generic_name' => (string) $s['generic_name'],
                'snapshot' => [
                    'strength' => $s['strength'] ?? null, 'dosage_form_id' => $s['dosage_form_id'] ?? null, 'form' => $s['form'] ?? null,
                    'route_id' => $s['route_id'] ?? null, 'route' => $s['route'] ?? null, 'use_count' => (int) ($s['use_count'] ?? 0),
                    'created_by_user_public_id' => $s['created_by_user_public_id'] ?? null,
                ],
                'status' => CustomBrandPromotionStatus::Pending->value,
                'submitted_at' => now(),
                'reviewed_by_super_admin_id' => null,
                'reviewed_at' => null,
                'decision' => null,
            ],
        );
    }
}
