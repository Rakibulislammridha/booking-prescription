<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\RefundReason;
use App\Domain\Billing\Enums\RefundStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\RefundFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money out, against exactly one payment, always with a reason code (SCHEMA §3.5, BRIEF §5.F). Offline devices
 * may never create one (OFFLINE §6.2) — the app rejects a refund from the `device` guard.
 *
 * @property int $id
 * @property int $payment_id
 * @property int $invoice_id
 * @property int $amount_paisa
 * @property PaymentMethod $method
 * @property RefundStatus $status
 * @property RefundReason $reason_code
 * @property string|null $reason_note
 * @property string|null $gateway_refund_id
 * @property array<string, mixed> $gateway_payload
 * @property int|null $requested_by_user_id
 * @property int|null $approved_by_user_id
 * @property int|null $cash_shift_id
 * @property CarbonImmutable|null $processed_at
 * @property-read Payment $payment
 * @property-read Invoice $invoice
 */
final class Refund extends TenantModel
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    protected static string $factory = RefundFactory::class;

    protected static bool $audited = true;

    /** @var array<int, string> */
    protected static array $auditedAttributes = [
        'payment_id', 'invoice_id', 'amount_paisa', 'method', 'status', 'reason_code', 'reason_note',
        'gateway_refund_id', 'requested_by_user_id', 'approved_by_user_id', 'cash_shift_id', 'processed_at',
    ];

    protected $table = 'refunds';

    protected $fillable = [
        'payment_id', 'invoice_id', 'amount_paisa', 'method', 'status', 'reason_code', 'reason_note',
        'gateway_refund_id', 'gateway_payload', 'requested_by_user_id', 'approved_by_user_id', 'cash_shift_id', 'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_paisa' => 'integer',
            'method' => PaymentMethod::class,
            'status' => RefundStatus::class,
            'reason_code' => RefundReason::class,
            'gateway_payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<CashShift, $this> */
    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    /**
     * Claims on the payment that are either taken or still pending — a second refund may not exceed the rest.
     *
     * @param  Builder<Refund>  $query
     */
    public function scopeClaiming(Builder $query): void
    {
        $query->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Approved->value, RefundStatus::Processed->value]);
    }
}
