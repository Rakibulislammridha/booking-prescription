<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Carbon\CarbonImmutable;
use Database\Factories\Tenant\CouponRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The fact that a coupon was used on one invoice (SCHEMA §3.5). UNIQUE (invoice_id) — the database, not the
 * application, is what stops a double redemption. `created_at` only.
 *
 * @property int $id
 * @property int $coupon_id
 * @property int $invoice_id
 * @property int $patient_id
 * @property int $amount_paisa
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

    protected $fillable = ['coupon_id', 'invoice_id', 'patient_id', 'amount_paisa'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_paisa' => 'integer',
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
