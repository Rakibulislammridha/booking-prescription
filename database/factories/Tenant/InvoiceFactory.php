<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft invoice with no lines. Prefer the Actions (CreateInvoice / IssueInvoice) in billing tests — the
 * factory exists for isolation/permission fixtures.
 *
 * @extends Factory<Invoice>
 */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'branch_id' => Branch::factory(),
            'status' => InvoiceStatus::Draft,
            'subtotal_paisa' => 0,
            'discount_paisa' => 0,
            'coupon_discount_paisa' => 0,
            'vat_paisa' => 0,
            'total_paisa' => 0,
            'paid_paisa' => 0,
        ];
    }

    public function issued(int $totalPaisa = 80000): static
    {
        return $this->state(fn () => [
            'status' => InvoiceStatus::Issued,
            'subtotal_paisa' => $totalPaisa,
            'total_paisa' => $totalPaisa,
            'issued_at' => now(),
        ]);
    }
}
