<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialCancelled;
use App\Domain\Serials\Services\CapacityService;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/**
 * booked|checked_in → cancelled with a reason code (SERIAL_ENGINE §6, §15). refund_eligible = true when cancelled by
 * the clinic/system, or by the patient before `serial.cancel_cutoff_minutes` of planned_start_at; billing decides
 * the money. Returns the serial and the eligibility.
 */
final class CancelSerial
{
    public function __construct(
        private readonly SerialTransition $transition,
        private readonly Settings $settings,
        private readonly CapacityService $capacity,
    ) {}

    /** @return array{serial: Serial, refund_eligible: bool} */
    public function handle(Serial $serial, CancelReason $reason, Actor $actor, ?string $note = null): array
    {
        $result = DB::transaction(function () use ($serial, $reason, $actor, $note): array {
            /** @var SessionInstance $session */
            $session = SessionInstance::query()->findOrFail($serial->session_instance_id);
            $cancelled = $this->transition->apply($serial, SerialStatus::Cancelled, $actor, ['session' => $session, 'cancel_reason_code' => $reason, 'reason' => $note]);

            $minutesBefore = (int) floor(($session->planned_start_at->getTimestamp() - now()->getTimestamp()) / 60);
            $byPatient = $actor->userId === null && $actor->deviceId === null && $actor->patientId !== null;
            $cutoff = (int) $this->settings->get('serial.cancel_cutoff_minutes');
            $refundEligible = ! $byPatient || $minutesBefore >= $cutoff;

            SerialCancelled::dispatch($cancelled, $session, $reason->value, $byPatient ? 'patient' : ($actor->role ?? $actor->source), $minutesBefore, $refundEligible);

            return ['serial' => $cancelled, 'refund_eligible' => $refundEligible, 'session_public_id' => $session->public_id];
        });

        $this->capacity->forget($result['session_public_id']);

        return ['serial' => $result['serial'], 'refund_eligible' => $result['refund_eligible']];
    }
}
