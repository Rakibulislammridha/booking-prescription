<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Patients\Enums\ConsentChannel;
use App\Domain\Patients\Enums\ConsentStatus;
use App\Domain\Patients\Enums\ConsentType;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\PatientConsentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only consent / data-sharing log (SCHEMA §3.2); revocation is a new row. created_at only.
 *
 * @property int $id
 * @property int $patient_id
 * @property ConsentType $type
 * @property ConsentStatus $status
 * @property string $policy_version
 * @property ConsentChannel $channel
 * @property int|null $captured_by_user_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $signature_data
 * @property array<string, mixed> $evidence
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 * @property-read Patient $patient
 */
final class PatientConsent extends TenantModel
{
    /** @use HasFactory<PatientConsentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static string $factory = PatientConsentFactory::class;

    protected static bool $audited = true;

    protected $table = 'patient_consents';

    protected $fillable = [
        'patient_id', 'type', 'status', 'policy_version', 'channel', 'captured_by_user_id', 'ip', 'user_agent',
        'signature_data', 'evidence', 'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ConsentType::class,
            'status' => ConsentStatus::class,
            'channel' => ConsentChannel::class,
            'signature_data' => 'encrypted',
            'evidence' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }
}
