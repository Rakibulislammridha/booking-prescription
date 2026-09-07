<?php

declare(strict_types=1);

namespace App\Domain\Serials\Data;

use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialSource;
use Carbon\CarbonImmutable;

/**
 * Input of AllocateSerial (SERIAL_ENGINE §4.1). `number` is set only by AllocateFromBlock: the device already issued
 * that number offline, so the server must issue *it* rather than the block cursor (OFFLINE §6.3).
 */
final readonly class AllocationRequest
{
    public function __construct(
        public int $sessionInstanceId,
        public SerialPool $pool,             // online|counter|buffer
        public SerialSource $source,         // online|counter|walkin|kiosk|followup|offline
        public SerialPriority $priority = SerialPriority::Normal,
        public ?int $patientId = null,       // null only for kiosk-before-OTP flows; must be set before check-in
        public ?int $appointmentId = null,   // set by the booking flow after the serial exists, or pre-created
        public ?string $clientEventId = null, // ULID; idempotency key (offline replay, and online double-submit)
        public ?int $actorUserId = null,     // null for public site / kiosk
        public ?CarbonImmutable $slotStartAt = null, // slot mode only
        public ?int $transferredFromSerialId = null,
        public ?int $serialBlockId = null,   // only for source = offline (AllocateFromBlock delegates here)
        public ?int $receptionDeviceId = null, // only for source = offline
        public ?int $number = null,          // only for source = offline: the number the device issued
        public ?string $priorityReason = null,
    ) {}

    public function isOffline(): bool
    {
        return $this->source === SerialSource::Offline && $this->serialBlockId !== null;
    }

    /** @param  array<string, mixed>  $overrides */
    public function with(array $overrides): self
    {
        $args = get_object_vars($this);

        return new self(...array_merge($args, $overrides));
    }
}
