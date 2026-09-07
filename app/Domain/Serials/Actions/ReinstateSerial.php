<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialReinstated;
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
 * no_show → checked_in (present) | booked (SERIAL_ENGINE §8): passed_count = 0, position by the elderly rule so the
 * reinstated patient is called soon but not ahead of the two already standing. Any number of times while open.
 */
final class ReinstateSerial
{
    public function __construct(
        private readonly SerialTransition $transition,
        private readonly PositionService $positions,
        private readonly SerialEventWriter $events,
        private readonly Settings $settings,
    ) {}

    public function handle(Serial $serial, Actor $actor, bool $present = true): Serial
    {
        return DB::transaction(function () use ($serial, $actor, $present): Serial {
            /** @var Serial $locked */
            $locked = Serial::query()->whereKey($serial->id)->lockForUpdate()->firstOrFail();
            $session = SessionLocks::lockSession($locked->session_instance_id);

            if (! $session->acceptsSerials()) {
                throw new SessionNotAcceptingSerials($session);
            }

            $reinstated = $this->transition->apply($locked, $present ? SerialStatus::CheckedIn : SerialStatus::Booked, $actor, ['session' => $session]);

            $from = $reinstated->position;
            $to = $this->positions->afterNowServing($session, max(0, (int) $this->settings->get('serial.elderly_skip')), $reinstated->id, $actor);
            $reinstated->forceFill(['position' => $to])->save();

            $this->events->write($session, $reinstated, SerialEventType::Reordered, ['after' => null, 'before' => null, 'reason' => 'reinstated'], $actor, ['from_position' => $from, 'to_position' => $to, 'reason' => 'reinstated']);
            CountsRecalculator::bumpVersion($session->id);
            SerialReinstated::dispatch($reinstated, $session);

            return $reinstated;
        }, attempts: 3);
    }
}
