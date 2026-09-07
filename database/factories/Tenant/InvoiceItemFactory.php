<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Billing\Enums\InvoiceItemType;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvoiceItem> */
final class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'sort_order' => 1,
            'type' => InvoiceItemType::Consultation,
            'description' => 'Consultation',
            'quantity' => 1,
            'unit_price_paisa' => 80000,
            'line_total_paisa' => 80000,
            'doctor_share_paisa' => 0,
            'clinic_share_paisa' => 0,
        ];
    }
}
