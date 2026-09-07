<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\Central\SubscriptionInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int|null $subscription_id
 * @property string $number
 * @property SubscriptionInvoiceStatus $status
 * @property int $total_paisa
 * @property array<int, array<string, mixed>> $line_items
 */
final class SubscriptionInvoice extends CentralModel
{
    /** @use HasFactory<SubscriptionInvoiceFactory> */
    use HasFactory, HasPublicId;

    protected static string $factory = SubscriptionInvoiceFactory::class;

    protected $table = 'public.subscription_invoices';

    protected $fillable = [
        'tenant_id', 'subscription_id', 'number', 'status', 'period_start', 'period_end', 'subtotal_paisa', 'discount_paisa',
        'tax_paisa', 'total_paisa', 'paid_paisa', 'line_items', 'issued_at', 'due_at', 'paid_at', 'voided_at', 'dunning_step', 'pdf_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionInvoiceStatus::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'subtotal_paisa' => 'integer',
            'discount_paisa' => 'integer',
            'tax_paisa' => 'integer',
            'total_paisa' => 'integer',
            'paid_paisa' => 'integer',
            'line_items' => 'array',
            'issued_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'dunning_step' => 'integer',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<SubscriptionPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }
}
