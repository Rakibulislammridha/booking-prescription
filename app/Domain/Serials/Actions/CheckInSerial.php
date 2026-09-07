<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Exceptions\SessionNotAcceptingSerials;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/** booked → checked_in ("mark arrived" and "check in" are the same edge). Guard: session not closed. */
final class CheckInSerial
{
    public function __construct(private readonly SerialTransition $transition) {}

    public function handle(Serial $serial, Actor $actor, ?string $clientEventId = null): Serial
    {
        return DB::transaction(function () use ($serial, $actor, $clientEventId): Serial {
            /** @var SessionInstance $session */
            $session = SessionInstance::query()->findOrFail($serial->session_instance_id);

            if (! $session->acceptsSerials()) {
                throw new SessionNotAcceptingSerials($session);
            }

            return $this->transition->apply($serial, SerialStatus::CheckedIn, $actor, ['session' => $session, 'client_event_id' => $clientEventId]);
        });
    }
}
