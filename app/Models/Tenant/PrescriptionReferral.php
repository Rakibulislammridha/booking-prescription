<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Prescription\Enums\ReferralType;
use App\Models\Tenant\Concerns\ImmutableWithPrescription;
use Database\Factories\Tenant\PrescriptionReferralFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referral block (SCHEMA §3.4); immutable with the parent.
 *
 * @property int $id
 * @property int $prescription_id
 * @property ReferralType $type
 * @property int|null $referred_to_doctor_id
 * @property int|null $external_diagnostic_centre_id
 * @property string $referred_to_name
 * @property string|null $referred_to_specialty
 * @property string|null $note
 * @property bool $is_urgent
 * @property-read Prescription $prescription
 */
final class PrescriptionReferral extends TenantModel
{
    /** @use HasFactory<PrescriptionReferralFactory> */
    use HasFactory, ImmutableWithPrescription;

    protected static string $factory = PrescriptionReferralFactory::class;

    protected $table = 'prescription_referrals';

    protected $fillable = ['prescription_id', 'type', 'referred_to_doctor_id', 'external_diagnostic_centre_id', 'referred_to_name', 'referred_to_specialty', 'note', 'is_urgent'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => ReferralType::class, 'referred_to_doctor_id' => 'integer', 'external_diagnostic_centre_id' => 'integer', 'is_urgent' => 'boolean'];
    }

    /** @return BelongsTo<Prescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function clientKey(): string
    {
        return 'r'.$this->id;
    }
}
