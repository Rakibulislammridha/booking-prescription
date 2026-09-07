<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\PaymentTxnStatus;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $invoice = Invoice::factory();

        return [
            'invoice_id' => $invoice,
            'patient_id' => fn (array $a) => (int) Invoice::query()->whereKey($a['invoice_id'])->value('patient_id'),
            'method' => PaymentMethod::Cash,
            'status' => PaymentTxnStatus::Succeeded,
            'amount_paisa' => 80000,
            'refunded_paisa' => 0,
            'idempotency_key' => fn () => (string) Str::ulid(),
            'paid_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => PaymentTxnStatus::Pending, 'paid_at' => null]);
    }
}
