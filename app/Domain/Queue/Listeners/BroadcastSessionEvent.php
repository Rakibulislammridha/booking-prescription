<?php

declare(strict_types=1);

namespace App\Domain\Queue\Listeners;

use App\Domain\Queue\Services\QueueBroadcaster;
use App\Domain\Serials\Events\DoctorArrived;
use App\Domain\Serials\Events\SessionCancelled;
use App\Domain\Serials\Events\SessionDelayed;
use App\Domain\Serials\Events\SessionEvent;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;

/**
 * The three session-level wire events (REALTIME.md §3.1): `session.delayed` (§10, one tap), `session.cancelled` and
 * `doctor.arrived` (dispatched by StartSession and by the first CallNext of a scheduled session).
 */
final class BroadcastSessionEvent
{
    public function __construct(private readonly QueueBroadcaster $broadcaster) {}

    public function handle(SessionEvent $event): void
    {
        if (! Tenancy::check()) {
            return;
        }

        $session = SessionInstance::query()->with(['doctor', 'branch'])->find($event->sessionInstanceId);

        if ($session === null) {
            return;
        }

        match (true) {
            $event instanceof SessionDelayed => $this->broadcaster->sessionDelayed($session, self::text($event, 'message')),
            $event instanceof SessionCancelled => $this->broadcaster->sessionCancelled($session, self::text($event, 'reason') ?? $session->cancel_reason),
            $event instanceof DoctorArrived => $this->broadcaster->doctorArrived($session),
            default => null,
        };
    }

    private static function text(SessionEvent $event, string $key): ?string
    {
        $value = $event->values[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
