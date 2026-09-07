<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\ImmutableWithPrescription;
use Database\Factories\Tenant\PrescriptionInvestigationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Investigation line with snapshot name/price (SCHEMA §3.4); immutable with the parent.
 *
 * @property int $id
 * @property int $prescription_id
 * @property int $sort_order
 * @property int|null $investigation_catalog_id
 * @property string $name
 * @property string|null $name_bn
 * @property int|null $price_paisa
 * @property int|null $external_diagnostic_centre_id
 * @property string|null $referral_note
 * @property bool $is_urgent
 * @property-read Prescription $prescription
 * @property-read ExternalDiagnosticCentre|null $externalCentre
 */
final class PrescriptionInvestigation extends TenantModel
{
    /** @use HasFactory<PrescriptionInvestigationFactory> */
    use HasFactory, ImmutableWithPrescription;

    protected static string $factory = PrescriptionInvestigationFactory::class;

    protected $table = 'prescription_investigations';

    protected $fillable = ['prescription_id', 'sort_order', 'investigation_catalog_id', 'name', 'name_bn', 'price_paisa', 'external_diagnostic_centre_id', 'referral_note', 'is_urgent'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'investigation_catalog_id' => 'integer', 'price_paisa' => 'integer', 'external_diagnostic_centre_id' => 'integer', 'is_urgent' => 'boolean'];
    }

    /** @return BelongsTo<Prescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /** @return BelongsTo<ExternalDiagnosticCentre, $this> */
    public function externalCentre(): BelongsTo
    {
        return $this->belongsTo(ExternalDiagnosticCentre::class, 'external_diagnostic_centre_id');
    }

    public function clientKey(): string
    {
        return 'x'.$this->id;
    }
}
