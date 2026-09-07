<?php

declare(strict_types=1);

namespace App\Domain\Reception\Handlers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\AppointmentType;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Services\AppointmentWriter;
use App\Domain\Booking\Services\FeeResolver;
use App\Domain\Reception\Enums\ConflictReason;
use App\Domain\Reception\Enums\ConflictResolution;
use App\Domain\Reception\Services\BlockCarver;
use App\Domain\Reception\Services\SerialPresenter;
use App\Domain\Reception\Sync\ReplayContext;
use App\Domain\Reception\Sync\ReplayHandler;
use App\Domain\Reception\Sync\ReplayOutcome;
use App\Domain\Reception\Sync\Resolution;
use App\Domain\Serials\Actions\AllocateFromBlock;
use App\Domain\Serials\Actions\AllocateSerial;
use App\Domain\Serials\Data\AllocationRequest;
use App\Domain\Serials\Enums\BlockStatus;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Exceptions\BlockNotIssuable;
use App\Domain\Serials\Exceptions\PoolExhausted;
use App\Domain\Serials\Services\CapacityService;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\DisplayCode;
use App\Domain\Serials\Services\PositionService;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\OfflineEvent;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SerialPool as PoolRow;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Str;

/**
 * OFFLINE §6.3 / §7.2 / §8.2 / §8.3: the device issued `number` from its block; the server issues *that* number
 * through AllocateFromBlock (block active) or from the released row (block released/revoked, number free — §8.2
 * "accepted with warning block_released"); a taken number is `serial_already_used`, a closed session
 * `session_closed`. Every accepted serial gets its appointment (channel offline, fee snapshot) in the same
 * transaction. Resolutions: reissue · move_to_session {session} · record_in_closed · discard {reason}.
 */
final class IssueSerialHandler implements ReplayHandler
{
    public function __construct(
        private readonly AllocateFromBlock $fromBlock,
        private readonly AllocateSerial $allocate,
        private readonly BlockCarver $carver,
        private readonly FeeResolver $fees,
        private readonly AppointmentWriter $writer,
        private readonly SerialPresenter $presenter,
        private readonly CapacityService $capacity,
        private readonly PositionService $positions,
        private readonly SerialEventWriter $events,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(OfflineEvent $event, ReplayContext $ctx, ?Resolution $resolution = null): ReplayOutcome
    {
        $p = $event->payload;
        $session = SessionInstance::query()->where('public_id', (string) ($p['sessionId'] ?? ''))->first();

        if ($session === null) {
            return ReplayOutcome::rejected('session_not_found', __('reception.sync.session_not_found'));
        }

        $number = (int) ($p['number'] ?? 0);

        if ($number < 1) {
            return ReplayOutcome::rejected('payload_invalid', __('reception.sync.payload_invalid'), $session->id);
        }

        $patient = $ctx->patientRef(isset($p['patientRef']) ? (string) $p['patientRef'] : null);

        if ($patient === null) {
            return ReplayOutcome::rejected('payload_invalid', __('reception.sync.unknown_patient'), $session->id);
        }

        // Exactly-once: the number was already issued for this event (a retry after a crash, or a resolution re-run).
        $existing = Serial::query()->where('session_instance_id', $session->id)->where('client_event_id', $event->client_event_id)->first();

        if ($existing !== null && $resolution?->resolution !== ConflictResolution::MoveToSession) {
            return $this->accept($ctx, $event, $existing, $patient, $session, ['noop' => true]);
        }

        if ($resolution !== null) {
            return $this->resolve($event, $ctx, $resolution, $session, $patient, $number);
        }

        $block = SerialBlock::query()->where('public_id', (string) ($p['blockId'] ?? ''))->first();

        if ($block === null) {
            return ReplayOutcome::rejected('block_unknown', __('reception.sync.block_unknown'), $session->id);
        }

        if ($block->reception_device_id !== $ctx->device->id) {
            return ReplayOutcome::rejected('block_not_owned', __('reception.sync.block_not_owned'), $session->id, $block->id);
        }

        if ($block->session_instance_id !== $session->id) {
            return ReplayOutcome::rejected('payload_invalid', __('reception.sync.payload_invalid'), $session->id, $block->id);
        }

        if (! $session->acceptsSerials()) {
            return $this->sessionClosed($session, $block);
        }

        $request = $this->request($event, $ctx, $session, $patient, $p);

        if ($block->status === BlockStatus::Active) {
            try {
                $serial = $this->fromBlock->handle($ctx->device->id, $block, $number, $request);
            } catch (BlockNotIssuable $e) {
                return $this->notIssuable($e, $session, $block->refresh(), $number);
            }

            return $this->accept($ctx, $event, $serial, $patient, $session, [], $block->id);
        }

        return $this->fromReleasedRow($event, $ctx, $session, $block, $number, $request, $patient);
    }

    /** Block released/revoked meanwhile (§8.2): the number is either taken (conflict) or still free on the row (accepted + warning). */
    private function fromReleasedRow(OfflineEvent $event, ReplayContext $ctx, SessionInstance $session, SerialBlock $block, int $number, AllocationRequest $request, Patient $patient): ReplayOutcome
    {
        $taken = Serial::query()->where('session_instance_id', $session->id)->where('number', $number)->first();

        if ($taken !== null || $number < $block->next_number || $number > $block->range_end) {
            return ReplayOutcome::conflict(ConflictReason::SerialAlreadyUsed, [
                'taken_by' => $taken === null ? null : ['display_code' => $taken->display_code, 'source' => $taken->source->value, 'booked_at' => $taken->booked_at->toIso8601String(), 'status' => $taken->status->value],
                'suggested_next' => $this->suggestedNext($session),
                'block_status' => $block->isRevoked() ? 'revoked' : $block->status->value,
                'display_code' => (string) ($event->payload['displayCode'] ?? DisplayCode::format($session->session_code, $number)),
            ], $session->id, $block->id);
        }

        $tiny = $this->carver->carve($block, $number, $ctx->device->id, $ctx->actor->id);

        if ($tiny === null) {
            return ReplayOutcome::conflict(ConflictReason::SerialAlreadyUsed, ['taken_by' => null, 'suggested_next' => $this->suggestedNext($session), 'block_status' => $block->status->value], $session->id, $block->id);
        }

        $serial = $this->fromBlock->handle($ctx->device->id, $tiny, $number, $request);

        return $this->accept($ctx, $event, $serial, $patient, $session, ['warning' => 'block_released'], $tiny->id);
    }

    private function resolve(OfflineEvent $event, ReplayContext $ctx, Resolution $resolution, SessionInstance $session, Patient $patient, int $number): ReplayOutcome
    {
        $p = $event->payload;

        switch ($resolution->resolution) {
            case ConflictResolution::Reissue:
                $serial = $this->allocatePool($event, $ctx, $session, $patient, $p);

                return $this->accept($ctx, $event, $serial, $patient, $session, ['reissued' => true, 'previous_display_code' => $p['displayCode'] ?? null]);

            case ConflictResolution::MoveToSession:
                $target = SessionInstance::query()->where('public_id', (string) $resolution->string('session'))->first();

                if ($target === null || $target->branch_id !== $ctx->device->branch_id) {
                    return ReplayOutcome::rejected('session_not_found', __('reception.sync.session_not_found'), $session->id);
                }

                if (! $target->acceptsSerials()) {
                    return $this->sessionClosed($target, null);
                }

                $serial = $this->allocatePool($event, $ctx, $target, $patient, $p);

                return $this->accept($ctx, $event, $serial, $patient, $target, ['moved' => true, 'from_session' => $session->public_id, 'previous_display_code' => $p['displayCode'] ?? null]);

            case ConflictResolution::RecordInClosed:
                return $this->recordInClosed($event, $ctx, $session, $patient, $number);

            default:
                return ReplayOutcome::rejected('discarded', (string) ($resolution->string('reason') ?? __('reception.sync.discarded')), $session->id);
        }
    }

    /**
     * Reissue / move: a fresh counter-pool number (free-list first), still `source = offline` and the same client_event_id.
     *
     * @param  array<string, mixed>  $p
     */
    private function allocatePool(OfflineEvent $event, ReplayContext $ctx, SessionInstance $session, Patient $patient, array $p): Serial
    {
        try {
            return ($this->allocate)(new AllocationRequest(
                sessionInstanceId: $session->id,
                pool: SerialPool::Counter,
                source: SerialSource::Offline,
                priority: self::priority($p),
                patientId: $patient->id,
                clientEventId: $event->client_event_id,
                actorUserId: $ctx->actor->id,
                receptionDeviceId: $ctx->device->id,
            ));
        } catch (PoolExhausted $e) {
            // buffer as the last resort — the patient is standing at the desk
            return ($this->allocate)(new AllocationRequest(
                sessionInstanceId: $session->id,
                pool: SerialPool::Buffer,
                source: SerialSource::Offline,
                priority: self::priority($p),
                patientId: $patient->id,
                clientEventId: $event->client_event_id,
                actorUserId: $ctx->actor->id,
                receptionDeviceId: $ctx->device->id,
            ));
        }
    }

    /**
     * §8.3 record_in_closed (Hospital Admin): the doctor saw the patient during the outage — the serial is written as
     * `completed` at the device's time. The state machine has no edge that inserts a finished serial, so this is a
     * documented direct write: same number discipline (the released row is carved so the number belongs to one owner),
     * serial_events `recorded_post_close`, audit, counts.
     */
    private function recordInClosed(OfflineEvent $event, ReplayContext $ctx, SessionInstance $session, Patient $patient, int $number): ReplayOutcome
    {
        $p = $event->payload;

        if (Serial::query()->where('session_instance_id', $session->id)->where('number', $number)->exists()) {
            return ReplayOutcome::conflict(ConflictReason::SerialAlreadyUsed, ['taken_by' => null, 'suggested_next' => null, 'block_status' => 'closed'], $session->id);
        }

        $block = SerialBlock::query()->where('public_id', (string) ($p['blockId'] ?? ''))->first();
        $tiny = $block === null ? null : $this->carver->carve($block, $number, $ctx->device->id, $ctx->actor->id);
        $at = $event->client_occurred_at;

        $serial = new Serial;
        $serial->forceFill([
            'public_id' => (string) Str::ulid(),
            'session_instance_id' => $session->id,
            'number' => $number,
            'display_code' => DisplayCode::format($session->session_code, $number),
            'position' => $this->positions->initial($session, $number, self::priority($p), null),
            'pool' => SerialPool::Counter,
            'status' => SerialStatus::Completed,
            'priority' => self::priority($p),
            'source' => SerialSource::Offline,
            'patient_id' => $patient->id,
            'serial_block_id' => $tiny?->id,
            'reception_device_id' => $ctx->device->id,
            'client_event_id' => $event->client_event_id,
            'issued_by_user_id' => $ctx->actor->id,
            'booked_at' => $at,
            'checked_in_at' => $at,
            'called_at' => $at,
            'consultation_started_at' => $at,
            'completed_at' => $at,
        ])->save();

        if ($tiny !== null) {
            $tiny->forceFill(['next_number' => $number + 1, 'status' => BlockStatus::Exhausted])->save();
        }

        $this->events->write($session, $serial, SerialEventType::RecordedPostClose, ['number' => $number, 'display_code' => $serial->display_code, 'client_occurred_at' => $at->toIso8601String()], $ctx->actorDto, ['to_status' => SerialStatus::Completed->value, 'client_event_id' => $event->client_event_id]);
        $this->audit->record(AuditAction::Create, $serial, null, ['number' => $number, 'display_code' => $serial->display_code, 'status' => 'completed', 'recorded_post_close' => true], ['actor_user_id' => $ctx->actor->id, 'actor_source' => 'offline_replay']);
        CountsRecalculator::run($session->id);
        $this->capacity->forget($session->public_id);

        $outcome = $this->accept($ctx, $event, $serial, $patient, $session, ['recorded_post_close' => true], $tiny?->id);
        $appointment = $serial->appointment_id === null ? null : Appointment::query()->find($serial->appointment_id);
        $appointment?->forceFill(['status' => AppointmentStatus::Completed])->save();

        return $outcome;
    }

    /** @param  array<string, mixed>  $extra */
    private function accept(ReplayContext $ctx, OfflineEvent $event, Serial $serial, Patient $patient, SessionInstance $session, array $extra = [], ?int $blockId = null): ReplayOutcome
    {
        $appointment = $this->ensureAppointment($ctx, $event, $serial, $patient, $session, $extra);
        $ctx->mapSerial($event->client_event_id, $serial);

        return ReplayOutcome::accepted(array_merge($extra, [
            'serial' => $this->presenter->present($serial->refresh(), $patient, $appointment),
            'appointment' => $appointment === null ? null : ['public_id' => $appointment->public_id, 'fee_paisa' => $appointment->fee_paisa, 'payment_status' => $appointment->payment_status->value],
        ]), $session->id, $blockId ?? $serial->serial_block_id);
    }

    /** @param  array<string, mixed>  $extra */
    private function ensureAppointment(ReplayContext $ctx, OfflineEvent $event, Serial $serial, Patient $patient, SessionInstance $session, array &$extra): ?Appointment
    {
        if ($serial->appointment_id !== null) {
            return Appointment::query()->find($serial->appointment_id);
        }

        $live = Appointment::query()->where('patient_id', $patient->id)->where('session_instance_id', $session->id)->live()->first();

        if ($live !== null) {
            $extra['warning'] = 'duplicate_booking';   // the patient already holds a live booking in this session

            return $live;
        }

        /** @var Doctor $doctor */
        $doctor = Doctor::query()->with('profile')->findOrFail($session->doctor_id);
        $type = AppointmentType::tryFrom((string) ($event->payload['appointmentType'] ?? 'new'));
        $fee = $this->fees->resolve($patient, $doctor, $session, BookingChannel::Offline, $type);

        return $this->writer->createForSerial($serial, $patient, $session, BookingChannel::Offline, $fee, $ctx->actorDto, ['reception_device_id' => $ctx->device->id]);
    }

    private function notIssuable(BlockNotIssuable $e, SessionInstance $session, SerialBlock $block, int $number): ReplayOutcome
    {
        if ($e->reason === 'block_not_owned') {
            return ReplayOutcome::rejected('block_not_owned', $e->getMessage(), $session->id, $block->id);
        }

        if ($e->reason === 'number_out_of_block_range' && $number < $block->next_number && $number >= $block->range_start) {
            $taken = Serial::query()->where('session_instance_id', $session->id)->where('number', $number)->first();

            if ($taken !== null) {
                return ReplayOutcome::conflict(ConflictReason::SerialAlreadyUsed, [
                    'taken_by' => ['display_code' => $taken->display_code, 'source' => $taken->source->value, 'booked_at' => $taken->booked_at->toIso8601String(), 'status' => $taken->status->value],
                    'suggested_next' => $this->suggestedNext($session),
                    'block_status' => $block->status->value,
                ], $session->id, $block->id);
            }
        }

        if ($e->reason === 'number_already_used') {
            return ReplayOutcome::conflict(ConflictReason::SerialAlreadyUsed, ['taken_by' => null, 'suggested_next' => $e->suggestedNext, 'block_status' => $block->status->value], $session->id, $block->id);
        }

        return ReplayOutcome::rejected('number_out_of_block_range', $e->getMessage(), $session->id, $block->id, $e->suggestedNext);
    }

    private function sessionClosed(SessionInstance $session, ?SerialBlock $block): ReplayOutcome
    {
        $alternatives = SessionInstance::query()
            ->where('doctor_id', $session->doctor_id)->where('branch_id', $session->branch_id)
            ->open()
            ->where('session_date', '>=', $session->session_date->toDateString())
            ->orderBy('session_date')->orderBy('session_code')
            ->limit(3)->get();
        $remaining = $this->capacity->remainingFor($alternatives->pluck('id')->all());

        return ReplayOutcome::conflict(ConflictReason::SessionClosed, [
            'session_status' => $session->status->value,
            'closed_at' => $session->actual_end_at?->toIso8601String(),
            'alternatives' => $alternatives->map(fn (SessionInstance $s) => [
                'public_id' => $s->public_id, 'code' => $s->session_code, 'date' => $s->session_date->toDateString(),
                'remaining' => ($remaining[$s->id]['counter'] ?? 0) + ($remaining[$s->id]['released'] ?? 0),
            ])->values()->all(),
        ], $session->id, $block?->id);
    }

    /** The number the desk would hand out next: the lowest released free-list number, else the counter cursor (SERIAL_ENGINE §3.5). */
    private function suggestedNext(SessionInstance $session): ?int
    {
        $released = SerialBlock::query()->where('session_instance_id', $session->id)->where('status', BlockStatus::Released->value)->whereColumn('next_number', '<=', 'range_end')->orderBy('range_start')->value('next_number');

        if ($released !== null) {
            return (int) $released;
        }

        $pool = PoolRow::query()->where('session_instance_id', $session->id)->where('pool', SerialPool::Counter->value)->first();

        return $pool === null || $pool->isExhausted() ? null : $pool->next_number;
    }

    /** @param  array<string, mixed>  $p */
    private function request(OfflineEvent $event, ReplayContext $ctx, SessionInstance $session, Patient $patient, array $p): AllocationRequest
    {
        return new AllocationRequest(
            sessionInstanceId: $session->id,
            pool: SerialPool::Counter,
            source: SerialSource::Offline,
            priority: self::priority($p),
            patientId: $patient->id,
            clientEventId: $event->client_event_id,
            actorUserId: $ctx->actor->id,
            receptionDeviceId: $ctx->device->id,
            priorityReason: isset($p['priorityReason']) && is_string($p['priorityReason']) ? $p['priorityReason'] : null,
        );
    }

    /** @param  array<string, mixed>  $p */
    private static function priority(array $p): SerialPriority
    {
        return SerialPriority::tryFrom((string) ($p['priority'] ?? 'normal')) ?? SerialPriority::Normal;
    }
}
