<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Enums\BlockStatus;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialNoShow;
use App\Domain\Serials\Events\SessionClosed;
use App\Domain\Serials\Exceptions\IllegalSessionState;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/**
 * any open → closed (SERIAL_ENGINE §2.5): locks all three pools (lock order), releases every active block
 * (OFFLINE §4.5 — unissued numbers stay on the released row as the free-list), auto no-shows the remaining
 * booked/checked_in serials with reason session_closed, actual_end_at = now(); SessionClosed after commit.
 */
final class CloseSession
{
    public function __construct(
        private readonly SerialTransition $transition,
        private readonly ReleaseBlock $releaseBlock,
    ) {}

    public function handle(SessionInstance $instance, Actor $actor, ?string $reason = null): SessionInstance
    {
        return DB::transaction(function () use ($instance, $actor, $reason): SessionInstance {
            SessionLocks::lockPools($instance->id);
            $session = SessionLocks::lockSession($instance->id);

            if ($session->status === SessionStatus::Closed) {
                return $session;   // idempotent (sessions:close-stale may race a manual close)
            }

            if ($session->status === SessionStatus::Cancelled) {
                throw new IllegalSessionState($session, 'close');
            }

            foreach (SerialBlock::query()->where('session_instance_id', $session->id)->where('status', BlockStatus::Active->value)->orderBy('range_start')->get() as $block) {
                $this->releaseBlock->handle($block, $actor, 'session_closed');
            }

            $remaining = Serial::query()
                ->where('session_instance_id', $session->id)
                ->whereIn('status', [SerialStatus::Booked->value, SerialStatus::CheckedIn->value])
                ->orderBy('position')->orderBy('number')
                ->get();

            foreach ($remaining as $serial) {
                $noShow = $this->transition->apply($serial, SerialStatus::NoShow, $actor, ['session' => $session, 'no_show_reason' => 'session_closed', 'reason' => $reason ?? 'session_closed']);
                SerialNoShow::dispatch($noShow, $session, 'session_closed');
            }

            $session->forceFill([
                'status' => SessionStatus::Closed,
                'actual_end_at' => now(),
                'closed_by_user_id' => $actor->userId,
                'now_serving_serial_id' => null,
                'notes' => $reason === null ? $session->notes : mb_substr($reason, 0, 255),
            ])->save();

            CountsRecalculator::run($session->id);
            SessionClosed::dispatch($session, $actor, ['reason' => $reason, 'no_showed' => $remaining->count()]);

            return $session->refresh();
        }, attempts: 3);
    }
}
