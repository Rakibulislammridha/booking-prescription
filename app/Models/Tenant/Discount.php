<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Billing\Enums\DiscountReason;
use App\Domain\Billing\Enums\DiscountType;
use Database\Factories\Tenant\DiscountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A waiver on one invoice, with reason, author and (above the settings threshold) approver — SCHEMA §3.5.
 *
 * @property int $id
 * @property int $invoice_id
 * @property DiscountType $type
 * @property string $value
 * @property int $amount_paisa
 * @property DiscountReason $reason_code
 * @property string|null $note
 * @property int|null $applied_by_user_id
 * @property int|null $approved_by_user_id
 * @property-read Invoice $invoice
 */
final class Discount extends TenantModel
{
    /** @use HasFactory<DiscountFactory> */
    use HasFactory;

    protected static string $factory = DiscountFactory::class;

    protected static bool $audited = true;

    protected $table = 'discounts';

    protected $fillable = [
        'invoice_id', 'type', 'value', 'amount_paisa', 'reason_code', 'note', 'applied_by_user_id', 'approved_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'amount_paisa' => 'integer',
            'reason_code' => DiscountReason::class,
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
