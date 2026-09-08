<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A room exists and the patient may be told how to reach it (BRIEF §5.J: the patient needs a join link).
 * Scalars only — a queued listener must not carry a tenant model across a serialisation boundary.
 */
final class TelemedicineInviteIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $roomId,
        public readonly int $appointmentId,
        public readonly int $patientId,
        public readonly ?int $serialId,
        public readonly string $joinUrl,
        public readonly string $expiresAt,
    ) {}
}
