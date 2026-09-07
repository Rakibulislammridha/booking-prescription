<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Database\Factories\Central\CustomBrandPromotionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cross-tenant review queue for tenant custom brands (SCHEMA §2.18). status values are App\Domain\Catalog\Enums\CustomBrandPromotionStatus.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $custom_brand_id
 * @property string $brand_name
 * @property string|null $manufacturer
 * @property int|null $generic_id
 * @property string|null $generic_name
 * @property array<string, mixed> $snapshot
 * @property string $status
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $reviewed_by_super_admin_id
 * @property CarbonImmutable|null $reviewed_at
 * @property array<string, mixed>|null $decision
 * @property-read SuperAdmin|null $reviewedBy
 */
final class CustomBrandPromotion extends CentralModel
{
    /** @use HasFactory<CustomBrandPromotionFactory> */
    use HasFactory, HasPublicId;

    protected static string $factory = CustomBrandPromotionFactory::class;

    protected $table = 'public.custom_brand_promotions';

    protected $fillable = [
        'tenant_id', 'custom_brand_id', 'brand_name', 'manufacturer', 'generic_id', 'generic_name', 'snapshot', 'status',
        'submitted_at', 'reviewed_by_super_admin_id', 'reviewed_at', 'decision',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'decision' => 'array',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<SuperAdmin, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'reviewed_by_super_admin_id');
    }
}
