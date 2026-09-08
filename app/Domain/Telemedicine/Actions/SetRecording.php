<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Actions;

use App\Domain\Patients\Enums\ConsentStatus;
use App\Domain\Patients\Enums\ConsentType;
use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Exceptions\CallNotLive;
use App\Domain\Telemedicine\Exceptions\RecordingNotAllowed;
use App\Domain\Telemedicine\Services\TelemedicineAuditor;
use App\Models\Tenant\PatientConsent;
use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\TelemedicineSession;

/**
 * Recording a consultation needs BOTH the clinic's switch (`telemedicine.recording_enabled`, snapshotted onto
 * the room) and the patient's own granted `telemedicine` consent (`patient_consents`, BRIEF §5.H). Either
 * missing is a refusal, not a silent no-op — a doctor who pressed record must know it is not recording.
 *
 * The file itself arrives later: the provider posts `recording_finished` and `HandleProviderWebhook` writes the
 * ENC `recording_path`. What this action owns is the decision and the audit trail of it.
 */
final class SetRecording
{
    public function __construct(private readonly TelemedicineAuditor $auditor) {}

    public function handle(TelemedicineRoom $room, bool $on, Actor $actor): TelemedicineSession
    {
        $session = TelemedicineSession::query()
            ->where('telemedicine_room_id', $room->id)
            ->whereNull('ended_at')
            ->orderByDesc('id')
            ->first() ?? throw new CallNotLive;

        if ($on && (! $room->recordingAllowed() || ! $this->consented($room))) {
            throw new RecordingNotAllowed;
        }

        $settings = $room->settings;
        $settings['recording_active'] = $on;
        $room->forceFill(['settings' => $settings])->save();

        $this->auditor->recording($session, $room, $on ? 'started' : 'stopped');

        return $session;
    }

    private function consented(TelemedicineRoom $room): bool
    {
        return PatientConsent::query()
            ->where('patient_id', $room->appointment->patient_id)
            ->where('type', ConsentType::Telemedicine->value)
            ->where('status', ConsentStatus::Granted->value)
            ->exists();
    }
}
