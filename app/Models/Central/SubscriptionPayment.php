<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Domain\SaaS\Enums\SubscriptionPaymentMethod;
use App\Domain\SaaS\Enums\SubscriptionPaymentStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\Central\SubscriptionPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $subscription_invoice_id
 * @property SubscriptionPaymentMethod $method
 * @property SubscriptionPaymentStatus $status
 * @property int $amount_paisa
 */
final class SubscriptionPayment extends CentralModel
{
    /** @use HasFactory<SubscriptionPaymentFactory> */
    use HasFactory, HasPublicId;

    protected static string $factory = SubscriptionPaymentFactory::class;

    protected $table = 'public.subscription_payments';

    protected $fillable = [
        'tenant_id', 'subscription_invoice_id', 'method', 'status', 'amount_paisa', 'gateway_txn_id', 'gateway_payload',
        'idempotency_key', 'paid_at', 'recorded_by_super_admin_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => SubscriptionPaymentMethod::class,
            'status' => SubscriptionPaymentStatus::class,
            'amount_paisa' => 'integer',
            'gateway_payload' => 'array',
            'paid_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<SubscriptionInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }

    /** @return BelongsTo<SuperAdmin, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'recorded_by_super_admin_id');
    }
}
