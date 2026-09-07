<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Exceptions\IllegalTransition;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use Illuminate\Support\Facades\DB;

/** consultation_started_at (optional; set when the doctor opens the prescription). Status unchanged, no SerialStatusChanged. */
final class StartConsultation
{
    public function __construct(private readonly SerialEventWriter $events) {}

    public function handle(Serial $serial, Actor $actor): Serial
    {
        return DB::transaction(function () use ($serial, $actor): Serial {
            /** @var Serial $locked */
            $locked = Serial::query()->whereKey($serial->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SerialStatus::InConsultation) {
                throw new IllegalTransition($locked->status, SerialStatus::InConsultation);
            }

            if ($locked->consultation_started_at === null) {
                $locked->forceFill(['consultation_started_at' => now()])->save();
                $this->events->write($locked->session_instance_id, $locked, SerialEventType::ConsultationStarted, [], $actor);
            }

            return $locked;
        });
    }
}
