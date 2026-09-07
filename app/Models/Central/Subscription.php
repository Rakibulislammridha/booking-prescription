<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Domain\SaaS\Enums\BillingCycle;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Central\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property BillingCycle $billing_cycle
 * @property int $price_paisa
 * @property CarbonImmutable $current_period_start
 * @property CarbonImmutable $current_period_end
 * @property array<string, mixed> $feature_overrides
 * @property-read Plan $plan
 * @property-read Tenant $tenant
 */
final class Subscription extends CentralModel
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected static string $factory = SubscriptionFactory::class;

    protected $table = 'public.subscriptions';

    protected $fillable = [
        'tenant_id', 'plan_id', 'status', 'billing_cycle', 'price_paisa', 'current_period_start', 'current_period_end',
        'trial_ends_at', 'grace_until', 'auto_renew', 'cancel_at_period_end', 'cancelled_at', 'cancel_reason', 'feature_overrides',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_cycle' => BillingCycle::class,
            'price_paisa' => 'integer',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'trial_ends_at' => 'immutable_datetime',
            'grace_until' => 'immutable_datetime',
            'auto_renew' => 'boolean',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'immutable_datetime',
            'feature_overrides' => 'array',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<SubscriptionInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }
}
