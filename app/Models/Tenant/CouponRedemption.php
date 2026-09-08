<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Carbon\CarbonImmutable;
use Database\Factories\Tenant\CouponRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The fact that a coupon was used on one invoice (SCHEMA §3.5). `created_at` only.
 *
 * Three unique indexes, each guaranteeing a different thing: UNIQUE (invoice_id) stops the same bill being
 * discounted twice; UNIQUE (coupon_id, coupon_use_seq) and UNIQUE (coupon_id, patient_id, patient_use_seq) bound
 * the row count by `coupons.max_uses` / `max_uses_per_patient`, because `ApplyCoupon` only ever inserts an
 * ordinal that is `count + 1` and within the cap. The ordinals are assigned while holding FOR UPDATE on the
 * `coupons` row — the lock is the mechanism, these indexes are the backstop (SERIAL_ENGINE §4).
 *
 * @property int $id
 * @property int $coupon_id
 * @property int $invoice_id
 * @property int $patient_id
 * @property int $amount_paisa
 * @property int $coupon_use_seq
 * @property int $patient_use_seq
 * @property CarbonImmutable|null $created_at
 * @property-read Coupon $coupon
 * @property-read Invoice $invoice
 */
final class CouponRedemption extends TenantModel
{
    /** @use HasFactory<CouponRedemptionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static string $factory = CouponRedemptionFactory::class;

    protected static bool $audited = true;

    protected $table = 'coupon_redemptions';

    protected $fillable = ['coupon_id', 'invoice_id', 'patient_id', 'amount_paisa', 'coupon_use_seq', 'patient_use_seq'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_paisa' => 'integer',
            'coupon_use_seq' => 'integer',
            'patient_use_seq' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
