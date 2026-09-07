<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Prescription\Enums\DoseTiming;
use Database\Factories\Tenant\PrescriptionTemplateItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Template Rx line (SCHEMA §3.4): prescription_items minus prescription_id / info_url_slug / safety_overrides; not
 * immutable. On apply, the line is re-parsed from dose_json.normalized against today's presentation.
 *
 * @property int $id
 * @property int $prescription_template_id
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
 * @property bool $is_continued
 * @property-read PrescriptionTemplate $template
 */
final class PrescriptionTemplateItem extends TenantModel
{
    /** @use HasFactory<PrescriptionTemplateItemFactory> */
    use HasFactory;

    protected static string $factory = PrescriptionTemplateItemFactory::class;

    protected $table = 'prescription_template_items';

    protected $fillable = [
        'prescription_template_id', 'sort_order', 'generic_id', 'brand_id', 'strength_id', 'custom_brand_id', 'generic_name', 'brand_name',
        'strength', 'form', 'route', 'dose_schedule', 'dose_json', 'duration_days', 'duration_text', 'quantity', 'quantity_unit',
        'timing', 'instruction', 'instruction_bn', 'is_continued',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer', 'generic_id' => 'integer', 'brand_id' => 'integer', 'strength_id' => 'integer', 'custom_brand_id' => 'integer',
            'dose_json' => 'array', 'duration_days' => 'integer', 'quantity' => 'float', 'timing' => DoseTiming::class, 'is_continued' => 'boolean',
        ];
    }

    /** @return BelongsTo<PrescriptionTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PrescriptionTemplate::class, 'prescription_template_id');
    }
}
