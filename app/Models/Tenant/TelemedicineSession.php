<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\SessionEndReason;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\TelemedicineSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCHEMA §3.8. One call attempt: who joined, when they left, how long it lasted and how it ended. `visit_id`
 * points at the ORDINARY visit — there is no telemedicine prescription path (BRIEF §5.K).
 *
 * `participants` is `[{"role":"doctor|patient","joined_at":ts,"left_at":ts|null,"device":str}]`.
 * `recording_path` is ENC (SCHEMA §5.5): it is a storage key for footage of a consultation.
 *
 * @property int $id
 * @property int $telemedicine_room_id
 * @property int|null $visit_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property int|null $duration_seconds
 * @property SessionEndReason|null $end_reason
 * @property array<int, array<string, mixed>> $participants
 * @property array<string, mixed>|null $quality
 * @property string|null $recording_path
 * @property string|null $provider_session_id
 * @property-read TelemedicineRoom $room
 * @property-read Visit|null $visit
 */
final class TelemedicineSession extends TenantModel
{
    /** @use HasFactory<TelemedicineSessionFactory> */
    use HasFactory;

    protected static string $factory = TelemedicineSessionFactory::class;

    protected static bool $audited = true;

    /** @var array<int, string> */
    protected static array $auditedAttributes = ['telemedicine_room_id', 'visit_id', 'started_at', 'ended_at', 'duration_seconds', 'end_reason', 'participants', 'recording_path'];

    protected $table = 'telemedicine_sessions';

    protected $fillable = [
        'telemedicine_room_id', 'visit_id', 'started_at', 'ended_at', 'duration_seconds', 'end_reason',
        'participants', 'quality', 'recording_path', 'provider_session_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'duration_seconds' => 'integer',
            'end_reason' => SessionEndReason::class,
            'participants' => 'array',
            'quality' => 'array',
            'recording_path' => 'encrypted',
        ];
    }

    /** @return BelongsTo<TelemedicineRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(TelemedicineRoom::class, 'telemedicine_room_id');
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** Not a column — see TelemedicineRoom::getPatientIdAttribute(). Reached through the room's appointment. */
    public function getPatientIdAttribute(): int
    {
        return $this->room->appointment->patient_id;
    }

    public function isLive(): bool
    {
        return $this->ended_at === null;
    }

    /** The still-connected entry for a role, if any. */
    public function presenceOf(ParticipantRole $role): ?int
    {
        foreach ($this->participants as $index => $entry) {
            if (($entry['role'] ?? null) === $role->value && ($entry['left_at'] ?? null) === null) {
                return $index;
            }
        }

        return null;
    }

    public function hasJoined(ParticipantRole $role): bool
    {
        return $this->presenceOf($role) !== null;
    }
}
