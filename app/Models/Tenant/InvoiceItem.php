<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Billing\Enums\InvoiceItemType;
use Database\Factories\Tenant\InvoiceItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billed line (SCHEMA §3.5). `line_total_paisa = quantity * unit_price_paisa` is a database CHECK, and the
 * doctor's commission split is frozen here at issue time so a later rule change never rewrites history.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $sort_order
 * @property InvoiceItemType $type
 * @property string $description
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $doctor_id
 * @property int $quantity
 * @property int $unit_price_paisa
 * @property int $line_total_paisa
 * @property int|null $doctor_revenue_share_id
 * @property int $doctor_share_paisa
 * @property int $clinic_share_paisa
 * @property-read Invoice $invoice
 * @property-read Doctor|null $doctor
 */
final class InvoiceItem extends TenantModel
{
    /** @use HasFactory<InvoiceItemFactory> */
    use HasFactory;

    protected static string $factory = InvoiceItemFactory::class;

    protected $table = 'invoice_items';

    protected $fillable = [
        'invoice_id', 'sort_order', 'type', 'description', 'reference_type', 'reference_id', 'doctor_id', 'quantity',
        'unit_price_paisa', 'line_total_paisa', 'doctor_revenue_share_id', 'doctor_share_paisa', 'clinic_share_paisa',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'type' => InvoiceItemType::class,
            'reference_id' => 'integer',
            'quantity' => 'integer',
            'unit_price_paisa' => 'integer',
            'line_total_paisa' => 'integer',
            'doctor_share_paisa' => 'integer',
            'clinic_share_paisa' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** @return BelongsTo<DoctorRevenueShare, $this> */
    public function revenueShare(): BelongsTo
    {
        return $this->belongsTo(DoctorRevenueShare::class, 'doctor_revenue_share_id');
    }
}
