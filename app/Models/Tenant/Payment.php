<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\PaymentTxnStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Money received against an invoice (SCHEMA §3.5). Never edited in place and never deleted: a reversal is a
 * `refunds` row. Three unique indexes make a replay a no-op instead of a second charge — `idempotency_key`
 * (desk/API retry), `(reception_device_id, client_event_id)` (offline replay) and `(gateway, gateway_txn_id)`
 * (gateway callback).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $invoice_id
 * @property int $patient_id
 * @property string|null $receipt_number
 * @property PaymentMethod $method
 * @property PaymentTxnStatus $status
 * @property int $amount_paisa
 * @property int $refunded_paisa
 * @property PaymentGateway|null $gateway
 * @property string|null $gateway_txn_id
 * @property string|null $gateway_payment_ref
 * @property array<string, mixed> $gateway_payload
 * @property string $idempotency_key
 * @property string|null $client_event_id
 * @property int|null $received_by_user_id
 * @property int|null $reception_device_id
 * @property int|null $cash_shift_id
 * @property CarbonImmutable|null $paid_at
 * @property string|null $failed_reason
 * @property-read Invoice $invoice
 * @property-read Patient $patient
 * @property-read User|null $receivedBy
 * @property-read CashShift|null $cashShift
 * @property-read Collection<int, Refund> $refunds
 */
final class Payment extends TenantModel
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected static string $factory = PaymentFactory::class;

    protected static bool $publicId = true;

    protected static bool $assertsTenantId = true;

    protected static bool $audited = true;

    /** @var array<int, string> */
    protected static array $auditedAttributes = [
        'invoice_id', 'patient_id', 'receipt_number', 'method', 'status', 'amount_paisa', 'refunded_paisa', 'gateway',
        'gateway_txn_id', 'idempotency_key', 'client_event_id', 'received_by_user_id', 'cash_shift_id', 'paid_at', 'failed_reason',
    ];

    protected $table = 'payments';

    protected $fillable = [
        'tenant_id', 'invoice_id', 'patient_id', 'receipt_number', 'method', 'status', 'amount_paisa', 'refunded_paisa',
        'gateway', 'gateway_txn_id', 'gateway_payment_ref', 'gateway_payload', 'idempotency_key', 'client_event_id',
        'received_by_user_id', 'reception_device_id', 'cash_shift_id', 'paid_at', 'failed_reason',
    ];

    /** `RCT-2026-000101` from the per-schema sequence (SCHEMA §0.4). Offline replays keep their device receipt. */
    public static function nextReceiptNumber(?int $year = null): string
    {
        $next = (int) DB::connection('pgsql')->scalar("select nextval('receipt_number_seq')");

        return sprintf('RCT-%d-%06d', $year ?? (int) now()->format('Y'), $next);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentTxnStatus::class,
            'amount_paisa' => 'integer',
            'refunded_paisa' => 'integer',
            'gateway' => PaymentGateway::class,
            'gateway_payload' => 'array',
            'paid_at' => 'immutable_datetime',
        ];
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

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /** @return BelongsTo<CashShift, $this> */
    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** @param  Builder<Payment>  $query */
    public function scopeSettled(Builder $query): void
    {
        $query->whereIn('status', [
            PaymentTxnStatus::Succeeded->value,
            PaymentTxnStatus::Refunded->value,
            PaymentTxnStatus::PartiallyRefunded->value,
        ]);
    }

    /** What the clinic actually kept from this payment. */
    public function netPaisa(): int
    {
        return $this->status->isSettled() ? $this->amount_paisa - $this->refunded_paisa : 0;
    }

    public function refundablePaisa(): int
    {
        return max(0, $this->netPaisa());
    }
}
