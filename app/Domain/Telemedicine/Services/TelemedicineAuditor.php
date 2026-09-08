<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\SessionEndReason;
use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\TelemedicineSession;

/**
 * BRIEF §8 / ARCHITECTURE §8.1: a video consultation is a clinical event, so every join, leave and recording
 * action leaves a row. The `Auditable` model hooks only cover create/update; presence changes are edits to one
 * jsonb column and would otherwise be indistinguishable from each other in the log, so they are recorded
 * explicitly with the role and the room in `context`.
 *
 * `patient_id` is filled from the room's appointment so the patient-timeline audit query
 * (`audit_logs (patient_id, occurred_at DESC)`) shows a consultation the same way it shows a prescription.
 */
final class TelemedicineAuditor
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function joined(TelemedicineSession $session, TelemedicineRoom $room, ParticipantRole $role, string $device): void
    {
        $this->recorder->record(AuditAction::CheckIn, $session, null, null, $this->context($room, ['event' => 'join', 'role' => $role->value, 'device' => $device]));
    }

    public function left(TelemedicineSession $session, TelemedicineRoom $room, ParticipantRole $role): void
    {
        $this->recorder->record(AuditAction::Update, $session, null, null, $this->context($room, ['event' => 'leave', 'role' => $role->value]));
    }

    public function ended(TelemedicineSession $session, TelemedicineRoom $room, SessionEndReason $reason, int $seconds): void
    {
        $this->recorder->record(AuditAction::Update, $session, null, null, $this->context($room, ['event' => 'end', 'reason' => $reason->value, 'duration_seconds' => $seconds]));
    }

    /** A recording is clinical data leaving the consultation: `share` is the action that says so. */
    public function recording(TelemedicineSession $session, TelemedicineRoom $room, string $state, ?string $path = null): void
    {
        $this->recorder->record(AuditAction::Share, $session, null, null, $this->context($room, array_filter([
            'event' => 'recording',
            'state' => $state,
            'stored' => $path === null ? null : true,
        ], fn ($v) => $v !== null)));
    }

    public function opened(TelemedicineRoom $room): void
    {
        $this->recorder->record(AuditAction::Update, $room, null, null, $this->context($room, ['event' => 'room_opened']));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function context(TelemedicineRoom $room, array $extra): array
    {
        return [
            'module' => 'telemedicine',
            'room' => $room->room_name,
            'appointment_id' => $room->appointment_id,
            'patient_id' => $room->appointment->patient_id,
            ...$extra,
        ];
    }
}
