<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\PositionService;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use Illuminate\Support\Facades\DB;

/**
 * in_consultation → checked_in (SERIAL_ENGINE §6/§14): the patient stepped out or was skipped. position moved after
 * the next 2 waiting serials (the elderly rule), skip_count + 1, event `skipped`; now_serving cleared when it was this one.
 */
final class ReturnToQueue
{
    public function __construct(
        private readonly SerialTransition $transition,
        private readonly PositionService $positions,
        private readonly Settings $settings,
    ) {}

    public function handle(Serial $serial, Actor $actor, ?string $reason = null): Serial
    {
        return DB::transaction(function () use ($serial, $actor, $reason): Serial {
            /** @var Serial $locked */
            $locked = Serial::query()->whereKey($serial->id)->lockForUpdate()->firstOrFail();
            $session = SessionLocks::lockSession($locked->session_instance_id);

            $returned = $this->transition->apply($locked, SerialStatus::CheckedIn, $actor, ['session' => $session, 'reason' => $reason]);

            if ($session->now_serving_serial_id === $returned->id) {
                $session->forceFill(['now_serving_serial_id' => null])->save();
                $session->now_serving_serial_id = null;
            }

            $to = $this->positions->afterNowServing($session, max(0, (int) $this->settings->get('serial.elderly_skip')), $returned->id, $actor);
            $returned->forceFill(['position' => $to, 'skip_count' => $returned->skip_count + 1])->save();
            CountsRecalculator::bumpVersion($session->id);

            return $returned;
        }, attempts: 3);
    }
}
