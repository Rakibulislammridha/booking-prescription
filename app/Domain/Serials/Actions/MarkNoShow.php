<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialNoShow;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/** Manual no-show (booked|checked_in → no_show) by Reception/Doctor; reason `manual`. */
final class MarkNoShow
{
    public function __construct(private readonly SerialTransition $transition) {}

    public function handle(Serial $serial, Actor $actor, ?string $reason = null): Serial
    {
        return DB::transaction(function () use ($serial, $actor, $reason): Serial {
            /** @var SessionInstance $session */
            $session = SessionInstance::query()->findOrFail($serial->session_instance_id);
            $noShow = $this->transition->apply($serial, SerialStatus::NoShow, $actor, ['session' => $session, 'no_show_reason' => 'manual', 'reason' => $reason]);
            SerialNoShow::dispatch($noShow, $session, 'manual');

            return $noShow;
        });
    }
}
