<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialReinstatedAfterCancel;
use App\Domain\Serials\Exceptions\SessionNotAcceptingSerials;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\PositionService;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use Illuminate\Support\Facades\DB;

/**
 * cancelled → checked_in — ONLY from the offline-sync `status_regression` resolution (OFFLINE.md §8.4). Position by
 * the elderly rule; event `reinstated_after_cancel`; SerialReinstatedAfterCancel lets billing void a pending refund.
 */
final class ReinstateAfterCancel
{
    public function __construct(
        private readonly SerialTransition $transition,
        private readonly PositionService $positions,
        private readonly SerialEventWriter $events,
        private readonly Settings $settings,
    ) {}

    public function handle(Serial $serial, Actor $actor, ?string $priorRefundStatus = null): Serial
    {
        return DB::transaction(function () use ($serial, $actor, $priorRefundStatus): Serial {
            /** @var Serial $locked */
            $locked = Serial::query()->whereKey($serial->id)->lockForUpdate()->firstOrFail();
            $session = SessionLocks::lockSession($locked->session_instance_id);

            if (! $session->acceptsSerials()) {
                throw new SessionNotAcceptingSerials($session);
            }

            $reinstated = $this->transition->apply($locked, SerialStatus::CheckedIn, $actor, ['session' => $session, 'reinstate_after_cancel' => true]);
            $reinstated->forceFill(['cancelled_at' => null, 'cancel_reason_code' => null])->save();

            $from = $reinstated->position;
            $to = $this->positions->afterNowServing($session, max(0, (int) $this->settings->get('serial.elderly_skip')), $reinstated->id, $actor);
            $reinstated->forceFill(['position' => $to])->save();

            $this->events->write($session, $reinstated, SerialEventType::Reordered, ['reason' => 'reinstated_after_cancel'], $actor, ['from_position' => $from, 'to_position' => $to, 'reason' => 'reinstated_after_cancel']);
            CountsRecalculator::bumpVersion($session->id);
            SerialReinstatedAfterCancel::dispatch($reinstated, $session, $priorRefundStatus);

            return $reinstated;
        }, attempts: 3);
    }
}
