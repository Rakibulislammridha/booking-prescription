<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Domain\SaaS\Enums\SubscriptionPaymentMethod;
use App\Domain\SaaS\Enums\SubscriptionPaymentStatus;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\SubscriptionPayment;
use App\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubscriptionPayment> */
final class SubscriptionPaymentFactory extends Factory
{
    protected $model = SubscriptionPayment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $tenant = Tenant::factory();

        return [
            'tenant_id' => $tenant,
            'subscription_invoice_id' => SubscriptionInvoice::factory()->for($tenant, 'tenant'),
            'method' => SubscriptionPaymentMethod::Bkash,
            'status' => SubscriptionPaymentStatus::Pending,
            'amount_paisa' => 150000,
            'gateway_payload' => [],
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => ['status' => SubscriptionPaymentStatus::Succeeded, 'paid_at' => now(), 'gateway_txn_id' => $this->faker->uuid()]);
    }
}
