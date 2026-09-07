<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialCompleted;
use App\Domain\Serials\Services\ConsultAverage;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use Illuminate\Support\Facades\DB;

/**
 * in_consultation → completed: completed_at, the EMA of call-to-complete (SERIAL_ENGINE §13, after the row lock on
 * session_instances), now_serving_serial_id = null when it was this serial; SerialCompleted after commit.
 */
final class CompleteConsultation
{
    public function __construct(private readonly SerialTransition $transition) {}

    public function handle(Serial $serial, Actor $actor): Serial
    {
        return DB::transaction(function () use ($serial, $actor): Serial {
            /** @var Serial $locked */
            $locked = Serial::query()->whereKey($serial->id)->lockForUpdate()->firstOrFail();
            $session = SessionLocks::lockSession($locked->session_instance_id);

            $completed = $this->transition->apply($locked, SerialStatus::Completed, $actor, ['session' => $session]);

            $sample = ConsultAverage::sample($completed);
            $accepted = ConsultAverage::accepts($sample);

            $session->forceFill([
                'avg_consult_seconds' => ConsultAverage::next($session->avg_consult_seconds, $sample),
                'consult_samples' => $session->consult_samples + ($accepted ? 1 : 0),
                'now_serving_serial_id' => $session->now_serving_serial_id === $completed->id ? null : $session->now_serving_serial_id,
            ])->save();

            SerialCompleted::dispatch($completed, $session, $sample);

            return $completed;
        }, attempts: 3);
    }
}
