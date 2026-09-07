<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Enums\BlockStatus;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialCancelled;
use App\Domain\Serials\Events\SessionCancelled;
use App\Domain\Serials\Exceptions\IllegalSessionState;
use App\Domain\Serials\Services\CapacityService;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/**
 * scheduled|running|paused → cancelled (Hospital Admin/Doctor, or an override/leave): every non-terminal serial →
 * cancelled reason session_cancelled (SerialCancelled with refund_eligible = true for billing), active blocks
 * released, SessionCancelled after commit for the notification fan-out.
 */
final class CancelSession
{
    public function __construct(
        private readonly SerialTransition $transition,
        private readonly ReleaseBlock $releaseBlock,
        private readonly CapacityService $capacity,
    ) {}

    public function handle(SessionInstance $instance, Actor $actor, ?string $reason = null, bool $notifyPatients = true): SessionInstance
    {
        $session = DB::transaction(function () use ($instance, $actor, $reason, $notifyPatients): SessionInstance {
            SessionLocks::lockPools($instance->id);
            $session = SessionLocks::lockSession($instance->id);

            if ($session->status === SessionStatus::Cancelled) {
                return $session;   // idempotent
            }

            if ($session->status === SessionStatus::Closed) {
                throw new IllegalSessionState($session, 'cancel');
            }

            foreach (SerialBlock::query()->where('session_instance_id', $session->id)->where('status', BlockStatus::Active->value)->orderBy('range_start')->get() as $block) {
                $this->releaseBlock->handle($block, $actor, 'session_cancelled');
            }

            $live = Serial::query()
                ->where('session_instance_id', $session->id)
                ->whereIn('status', SerialStatus::nonTerminalValues())
                ->orderBy('position')->orderBy('number')
                ->get();

            foreach ($live as $serial) {
                if ($serial->status === SerialStatus::InConsultation) {
                    $serial = $this->transition->apply($serial, SerialStatus::CheckedIn, $actor, ['session' => $session, 'reason' => 'session_cancelled']);
                }

                if ($serial->status === SerialStatus::NoShow) {
                    $serial = $this->transition->apply($serial, SerialStatus::Booked, $actor, ['session' => $session, 'reason' => 'session_cancelled']);
                }

                $cancelled = $this->transition->apply($serial, SerialStatus::Cancelled, $actor, ['session' => $session, 'cancel_reason_code' => CancelReason::SessionCancelled, 'reason' => $reason]);
                $minutes = (int) floor(($session->planned_start_at->getTimestamp() - now()->getTimestamp()) / 60);
                SerialCancelled::dispatch($cancelled, $session, CancelReason::SessionCancelled->value, $actor->role ?? $actor->source, $minutes, true);
            }

            $session->forceFill([
                'status' => SessionStatus::Cancelled,
                'cancel_reason' => $reason === null ? null : mb_substr($reason, 0, 255),
                'closed_by_user_id' => $actor->userId,
                'now_serving_serial_id' => null,
                'actual_end_at' => $session->actual_start_at === null ? null : now(),
            ])->save();

            CountsRecalculator::run($session->id);
            SessionCancelled::dispatch($session, $actor, ['reason' => $reason, 'notify_patients' => $notifyPatients, 'cancelled_serials' => $live->count()]);

            return $session->refresh();
        }, attempts: 3);

        $this->capacity->forget($session->public_id);

        return $session;
    }
}
