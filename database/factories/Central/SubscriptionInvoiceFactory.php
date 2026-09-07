<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubscriptionInvoice> */
final class SubscriptionInvoiceFactory extends Factory
{
    protected $model = SubscriptionInvoice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'number' => 'SI-'.now()->year.'-'.$this->faker->unique()->numerify('######'),
            'status' => SubscriptionInvoiceStatus::Draft,
            'subtotal_paisa' => 150000,
            'total_paisa' => 150000,
            'line_items' => [['description' => 'Monthly plan', 'quantity' => 1, 'unit_paisa' => 150000, 'total_paisa' => 150000, 'feature_key' => null]],
        ];
    }

    public function issued(): static
    {
        return $this->state(fn () => ['status' => SubscriptionInvoiceStatus::Issued, 'issued_at' => now(), 'due_at' => now()->addDays(7)]);
    }
}
