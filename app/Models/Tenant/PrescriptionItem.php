<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Prescription\Enums\DoseTiming;
use App\Models\Tenant\Concerns\ImmutableWithPrescription;
use Database\Factories\Tenant\PrescriptionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rx line (SCHEMA §3.4). Snapshot text columns are the print source; soft ids exist for analytics and safety
 * re-checks only. dose_json is the ParsedLine of PRESCRIPTION.md §2.11; the scalar columns are its projections.
 *
 * @property int $id
 * @property int $prescription_id
 * @property int $sort_order
 * @property int|null $generic_id
 * @property int|null $brand_id
 * @property int|null $strength_id
 * @property int|null $custom_brand_id
 * @property string $generic_name
 * @property string|null $brand_name
 * @property string|null $strength
 * @property string|null $form
 * @property string|null $route
 * @property string|null $dose_schedule
 * @property array<string, mixed> $dose_json
 * @property int|null $duration_days
 * @property string|null $duration_text
 * @property float|null $quantity
 * @property string|null $quantity_unit
 * @property DoseTiming $timing
 * @property string|null $instruction
 * @property string|null $instruction_bn
 * @property string|null $info_url_slug
 * @property bool $is_continued
 * @property array<int, array<string, mixed>> $safety_overrides
 * @property-read Prescription $prescription
 */
final class PrescriptionItem extends TenantModel
{
    /** @use HasFactory<PrescriptionItemFactory> */
    use HasFactory, ImmutableWithPrescription;

    protected static string $factory = PrescriptionItemFactory::class;

    protected $table = 'prescription_items';

    protected $fillable = [
        'prescription_id', 'sort_order', 'generic_id', 'brand_id', 'strength_id', 'custom_brand_id', 'generic_name', 'brand_name',
        'strength', 'form', 'route', 'dose_schedule', 'dose_json', 'duration_days', 'duration_text', 'quantity', 'quantity_unit',
        'timing', 'instruction', 'instruction_bn', 'info_url_slug', 'is_continued', 'safety_overrides',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'generic_id' => 'integer',
            'brand_id' => 'integer',
            'strength_id' => 'integer',
            'custom_brand_id' => 'integer',
            'dose_json' => 'array',
            'duration_days' => 'integer',
            'quantity' => 'float',
            'timing' => DoseTiming::class,
            'is_continued' => 'boolean',
            'safety_overrides' => 'array',
        ];
    }

    /** @return BelongsTo<Prescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /** The `key` the writer uses for this row when loaded from the database (server-assigned; PRESCRIPTION.md §1.5). */
    public function clientKey(): string
    {
        return 'i'.$this->id;
    }
}
