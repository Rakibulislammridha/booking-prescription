<?php

declare(strict_types=1);

namespace App\Domain\Reception\Handlers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Reception\Enums\ConflictReason;
use App\Domain\Reception\Enums\ConflictResolution;
use App\Domain\Reception\Services\SerialPresenter;
use App\Domain\Reception\Sync\ReplayContext;
use App\Domain\Reception\Sync\ReplayHandler;
use App\Domain\Reception\Sync\ReplayOutcome;
use App\Domain\Reception\Sync\Resolution;
use App\Domain\Serials\Actions\CheckInSerial;
use App\Domain\Serials\Actions\ReinstateAfterCancel;
use App\Domain\Serials\Actions\ReinstateSerial;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\OfflineEvent;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialEvent;
use App\Models\Tenant\SessionInstance;

/**
 * OFFLINE §7.2 / §8.4: arrival is a physical fact the desk witnessed. booked → CheckInSerial; already
 * checked_in/in_consultation/completed → accepted noop; no_show → reinstated; cancelled → `status_regression`
 * conflict (reinstate {} · discard {}); a closed session → `session_closed` (record_in_closed · discard).
 */
final class CheckInHandler implements ReplayHandler
{
    public function __construct(
        private readonly CheckInSerial $checkIn,
        private readonly ReinstateSerial $reinstate,
        private readonly ReinstateAfterCancel $reinstateAfterCancel,
        private readonly SerialPresenter $presenter,
        private readonly SerialEventWriter $events,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(OfflineEvent $event, ReplayContext $ctx, ?Resolution $resolution = null): ReplayOutcome
    {
        $serial = $ctx->serialRef(isset($event->payload['serialRef']) ? (string) $event->payload['serialRef'] : null);

        if ($serial === null) {
            return ReplayOutcome::rejected('serial_not_found', __('reception.sync.serial_not_found'));
        }

        /** @var SessionInstance $session */
        $session = SessionInstance::query()->findOrFail($serial->session_instance_id);

        if ($resolution !== null) {
            return match ($resolution->resolution) {
                ConflictResolution::Reinstate => $this->reinstateAfterCancel($ctx, $serial, $session),
                ConflictResolution::RecordInClosed => $this->recordInClosed($event, $ctx, $serial, $session),
                default => ReplayOutcome::rejected('discarded', (string) ($resolution->string('reason') ?? __('reception.sync.discarded')), $session->id),
            };
        }

        switch ($serial->status) {
            case SerialStatus::Booked:
                if (! $session->acceptsSerials()) {
                    return $this->sessionClosed($session);
                }

                $checked = $this->checkIn->handle($serial, $ctx->actorDto, $event->client_event_id);
                $this->mirror($checked, AppointmentStatus::CheckedIn);

                return $this->accepted($checked, $session);

            case SerialStatus::CheckedIn:
            case SerialStatus::InConsultation:
            case SerialStatus::Completed:
                return $this->accepted($serial, $session, ['noop' => true]);

            case SerialStatus::NoShow:
                if (! $session->acceptsSerials()) {
                    return $this->sessionClosed($session);
                }

                $reinstated = $this->reinstate->handle($serial, $ctx->actorDto, true);
                $this->mirror($reinstated, AppointmentStatus::CheckedIn);

                return $this->accepted($reinstated, $session, ['reinstated' => true]);

            case SerialStatus::Cancelled:
                return $this->cancelledConflict($serial, $session);

            default:   // postponed: the serial moved on; the arrival is recorded against the new one by the desk
                return $this->accepted($serial, $session, ['noop' => true, 'warning' => 'postponed']);
        }
    }

    private function reinstateAfterCancel(ReplayContext $ctx, Serial $serial, SessionInstance $session): ReplayOutcome
    {
        if ($serial->status !== SerialStatus::Cancelled) {
            return $this->accepted($serial, $session, ['noop' => true]);
        }

        if (! $session->acceptsSerials()) {
            return $this->sessionClosed($session);
        }

        $appointment = $serial->appointment_id === null ? null : Appointment::query()->find($serial->appointment_id);
        $reinstated = $this->reinstateAfterCancel->handle($serial, $ctx->actorDto, $appointment?->payment_status->value);
        $appointment?->forceFill(['status' => AppointmentStatus::CheckedIn, 'cancel_reason_code' => null, 'cancelled_at' => null, 'cancelled_by_user_id' => null])->save();

        return $this->accepted($reinstated, $session, ['reinstated_after_cancel' => true]);
    }

    /** The patient was seen during the outage (Hospital Admin): completed at the device's time, event recorded_post_close. */
    private function recordInClosed(OfflineEvent $event, ReplayContext $ctx, Serial $serial, SessionInstance $session): ReplayOutcome
    {
        if ($serial->status === SerialStatus::Completed) {
            return $this->accepted($serial, $session, ['noop' => true]);
        }

        $at = $event->client_occurred_at;
        $serial->forceFill(['status' => SerialStatus::Completed, 'checked_in_at' => $serial->checked_in_at ?? $at, 'called_at' => $serial->called_at ?? $at, 'completed_at' => $at, 'no_show_at' => null])->save();
        $this->events->write($session, $serial, SerialEventType::RecordedPostClose, ['client_occurred_at' => $at->toIso8601String()], $ctx->actorDto, ['to_status' => SerialStatus::Completed->value, 'client_event_id' => $event->client_event_id]);
        $this->audit->record(AuditAction::CheckIn, $serial, null, ['status' => 'completed', 'recorded_post_close' => true], ['actor_user_id' => $ctx->actor->id, 'actor_source' => 'offline_replay']);
        CountsRecalculator::run($session->id);

        $appointment = $serial->appointment_id === null ? null : Appointment::query()->find($serial->appointment_id);
        $appointment?->forceFill(['status' => AppointmentStatus::Completed])->save();

        return $this->accepted($serial, $session, ['recorded_post_close' => true]);
    }

    /** appointments.status follows the serial (also done by SyncAppointmentWithSerial after commit; here so the same transaction is consistent). */
    private function mirror(Serial $serial, AppointmentStatus $status): void
    {
        if ($serial->appointment_id !== null) {
            Appointment::query()->find($serial->appointment_id)?->forceFill(['status' => $status])->save();
        }
    }

    private function cancelledConflict(Serial $serial, SessionInstance $session): ReplayOutcome
    {
        $cancelEvent = SerialEvent::query()->where('serial_id', $serial->id)->where('type', SerialEventType::Cancelled->value)->orderByDesc('id')->first();
        $appointment = $serial->appointment_id === null ? null : Appointment::query()->find($serial->appointment_id);

        return ReplayOutcome::conflict(ConflictReason::StatusRegression, [
            'display_code' => $serial->display_code,
            'cancelled_at' => $serial->cancelled_at?->toIso8601String(),
            'cancelled_by' => $cancelEvent === null ? 'system' : ($cancelEvent->actor_patient_id !== null ? 'patient' : ($cancelEvent->actor_user_id !== null ? 'staff' : 'system')),
            'cancel_reason_code' => $serial->cancel_reason_code?->value,
            'refund' => ['status' => $appointment === null ? 'none' : $appointment->payment_status->value, 'amount' => $appointment->fee_paisa ?? 0],
        ], $session->id);
    }

    private function sessionClosed(SessionInstance $session): ReplayOutcome
    {
        return ReplayOutcome::conflict(ConflictReason::SessionClosed, [
            'session_status' => $session->status->value,
            'closed_at' => $session->actual_end_at?->toIso8601String(),
            'alternatives' => [],
        ], $session->id);
    }

    /** @param  array<string, mixed>  $extra */
    private function accepted(Serial $serial, SessionInstance $session, array $extra = []): ReplayOutcome
    {
        return ReplayOutcome::accepted($extra + ['serial' => $this->presenter->present($serial->refresh())], $session->id);
    }
}
