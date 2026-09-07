<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\ImmutableWithPrescription;
use Database\Factories\Tenant\PrescriptionAdviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Advice line (SCHEMA §3.4 `prescription_advice`); immutable with the parent.
 *
 * @property int $id
 * @property int $prescription_id
 * @property int $sort_order
 * @property int|null $advice_snippet_id
 * @property string $text
 * @property string|null $text_bn
 * @property-read Prescription $prescription
 */
final class PrescriptionAdvice extends TenantModel
{
    /** @use HasFactory<PrescriptionAdviceFactory> */
    use HasFactory, ImmutableWithPrescription;

    protected static string $factory = PrescriptionAdviceFactory::class;

    protected $table = 'prescription_advice';

    protected $fillable = ['prescription_id', 'sort_order', 'advice_snippet_id', 'text', 'text_bn'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'advice_snippet_id' => 'integer'];
    }

    /** @return BelongsTo<Prescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function clientKey(): string
    {
        return 'a'.$this->id;
    }
}
