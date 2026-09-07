<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\RefundReason;
use App\Domain\Billing\Enums\RefundStatus;
use App\Models\Tenant\Payment;
use App\Models\Tenant\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Refund> */
final class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'invoice_id' => fn (array $a) => (int) Payment::query()->whereKey($a['payment_id'])->value('invoice_id'),
            'amount_paisa' => 80000,
            'method' => PaymentMethod::Cash,
            'status' => RefundStatus::Pending,
            'reason_code' => RefundReason::PatientCancelled,
        ];
    }
}
