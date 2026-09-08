<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Telemedicine\Enums\RoomStatus;
use App\Domain\Telemedicine\Enums\TelemedicineProvider;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\TelemedicineRoomFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCHEMA §3.8. One room per telemedicine appointment. `room_name` (`t{tenantId}-{ulid}`) is the provider room id
 * AND the row's public handle — it is what a signed patient link carries, so it must never be guessable and is
 * never derived from the bigint id. Join tokens are minted on demand and never stored (SCHEMA §3.8).
 *
 * Audited (BRIEF §8: a consultation is a clinical event).
 *
 * @property int $id
 * @property int $appointment_id
 * @property TelemedicineProvider $provider
 * @property string $room_name
 * @property RoomStatus $status
 * @property CarbonImmutable $scheduled_at
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $doctor_join_url_expires_at
 * @property CarbonImmutable|null $patient_join_url_expires_at
 * @property array<string, mixed> $settings
 * @property-read Appointment $appointment
 * @property-read Collection<int, TelemedicineSession> $sessions
 * @property-read TelemedicineSession|null $latestSession
 */
final class TelemedicineRoom extends TenantModel
{
    /** @use HasFactory<TelemedicineRoomFactory> */
    use HasFactory;

    protected static string $factory = TelemedicineRoomFactory::class;

    protected static bool $audited = true;

    /** @var array<int, string> */
    protected static array $auditedAttributes = ['appointment_id', 'provider', 'status', 'scheduled_at', 'opened_at', 'ended_at', 'settings'];

    protected $table = 'telemedicine_rooms';

    protected $fillable = [
        'appointment_id', 'provider', 'room_name', 'status', 'scheduled_at', 'opened_at', 'ended_at',
        'doctor_join_url_expires_at', 'patient_join_url_expires_at', 'settings',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => TelemedicineProvider::class,
            'status' => RoomStatus::class,
            'scheduled_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'doctor_join_url_expires_at' => 'immutable_datetime',
            'patient_join_url_expires_at' => 'immutable_datetime',
            'settings' => 'array',
        ];
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return HasMany<TelemedicineSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TelemedicineSession::class);
    }

    /** @return HasMany<TelemedicineSession, $this> */
    public function latestSession(): HasMany
    {
        return $this->sessions()->orderByDesc('started_at')->orderByDesc('id')->limit(1);
    }

    /**
     * Not a column: `AuditRecorder` fills `audit_logs.patient_id` from `getAttribute('patient_id')`, and a
     * consultation must appear on the patient's audit timeline next to their prescriptions (ARCHITECTURE §8.1).
     * A legacy-style accessor rather than an `Attribute`, because `Attribute<int, never>` is not covariant and
     * PHPStan level 6 rejects it.
     */
    public function getPatientIdAttribute(): int
    {
        return $this->appointment->patient_id;
    }

    /** @param  Builder<$this>  $query */
    public function scopeJoinable(Builder $query): void
    {
        $query->whereIn('status', [RoomStatus::Scheduled->value, RoomStatus::Open->value]);
    }

    public function getRouteKeyName(): string
    {
        return 'room_name';
    }

    public function recordingAllowed(): bool
    {
        return (bool) ($this->settings['recording'] ?? false);
    }

    public function maxMinutes(): int
    {
        $value = $this->settings['max_minutes'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : (int) config('telemedicine.room.max_minutes', 45);
    }
}
