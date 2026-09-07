<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Database\Factories\Tenant\DoctorProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 1:1 presentation and fee/follow-up rules for a doctor (SCHEMA §5.10).
 *
 * @property int $id
 * @property int $doctor_id
 * @property string|null $degrees
 * @property string|null $bmdc_reg_no
 * @property array<int, string> $languages
 * @property int $new_fee_paisa
 * @property int $followup_fee_paisa
 * @property int $free_followup_within_days
 * @property int $followup_within_days
 * @property bool $report_visit_free
 * @property int|null $telemedicine_fee_paisa
 * @property int $online_booking_fee_delta_paisa
 * @property bool $advance_payment_required
 * @property array<string, mixed> $prefs
 */
final class DoctorProfile extends TenantModel
{
    /** @use HasFactory<DoctorProfileFactory> */
    use HasFactory;

    protected static string $factory = DoctorProfileFactory::class;

    protected $table = 'doctor_profiles';

    protected $fillable = [
        'doctor_id', 'degrees', 'degrees_bn', 'bmdc_reg_no', 'designation', 'bio', 'bio_bn', 'experience_years', 'languages',
        'new_fee_paisa', 'followup_fee_paisa', 'free_followup_within_days', 'followup_within_days', 'report_visit_free',
        'telemedicine_fee_paisa', 'online_booking_fee_delta_paisa', 'advance_payment_required', 'chamber_notes', 'prefs',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'experience_years' => 'integer',
            'languages' => 'array',
            'new_fee_paisa' => 'integer',
            'followup_fee_paisa' => 'integer',
            'free_followup_within_days' => 'integer',
            'followup_within_days' => 'integer',
            'report_visit_free' => 'boolean',
            'telemedicine_fee_paisa' => 'integer',
            'online_booking_fee_delta_paisa' => 'integer',
            'advance_payment_required' => 'boolean',
            'prefs' => 'array',
        ];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
