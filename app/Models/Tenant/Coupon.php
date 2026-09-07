<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Billing\Enums\DiscountType;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\CouponFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A promotional code (SCHEMA §3.5). `uses_count` is a cached counter; the authoritative record is
 * `coupon_redemptions`, which is UNIQUE per invoice.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property DiscountType $type
 * @property string $value
 * @property int|null $max_discount_paisa
 * @property int $min_invoice_paisa
 * @property int|null $max_uses
 * @property int $max_uses_per_patient
 * @property int $uses_count
 * @property array<string, mixed> $applies_to
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property bool $is_active
 * @property int|null $created_by_user_id
 * @property-read Collection<int, CouponRedemption> $redemptions
 */
final class Coupon extends TenantModel
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    protected static string $factory = CouponFactory::class;

    protected static bool $audited = true;

    protected $table = 'coupons';

    protected $fillable = [
        'code', 'name', 'type', 'value', 'max_discount_paisa', 'min_invoice_paisa', 'max_uses', 'max_uses_per_patient',
        'uses_count', 'applies_to', 'valid_from', 'valid_until', 'is_active', 'created_by_user_id',
    ];

    protected static function booted(): void
    {
        self::saving(function (self $coupon): void {
            $coupon->setAttribute('code', mb_strtoupper(trim((string) $coupon->getAttribute('code'))));
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'max_discount_paisa' => 'integer',
            'min_invoice_paisa' => 'integer',
            'max_uses' => 'integer',
            'max_uses_per_patient' => 'integer',
            'uses_count' => 'integer',
            'applies_to' => 'array',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /** @param  Builder<Coupon>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * One of the `applies_to` allow-lists; an absent or empty list means "any".
     *
     * @return array<int, mixed>
     */
    public function allowList(string $key): array
    {
        $value = $this->applies_to[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }
}
