<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Events;

use App\Domain\Telemedicine\Enums\ParticipantRole;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Somebody joined or left a live call — the waiting room's "the doctor is here" signal. */
final class ParticipantPresenceChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $roomId,
        public readonly int $sessionId,
        public readonly ParticipantRole $role,
        public readonly bool $present,
    ) {}
}
