<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Patients\Enums\OtpChannel;
use App\Domain\Patients\Enums\OtpPurpose;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\PatientOtpCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-time codes (SCHEMA §3.2): bcrypt hash, 300 s expiry, 5 attempts; rows older than 24 h are pruned.
 * Not audited — the login itself is (AuditAction::Login on the patient).
 *
 * @property int $id
 * @property string $mobile
 * @property int|null $patient_id
 * @property OtpPurpose $purpose
 * @property string $code_hash
 * @property OtpChannel $channel
 * @property int $attempts
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property string|null $ip
 * @property CarbonImmutable $created_at
 */
final class PatientOtpCode extends TenantModel
{
    /** @use HasFactory<PatientOtpCodeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const MAX_ATTEMPTS = 5;

    protected static string $factory = PatientOtpCodeFactory::class;

    protected $table = 'patient_otp_codes';

    protected $fillable = ['mobile', 'patient_id', 'purpose', 'code_hash', 'channel', 'attempts', 'expires_at', 'consumed_at', 'ip'];

    protected $hidden = ['code_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'channel' => OtpChannel::class,
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function attemptsExhausted(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }
}
