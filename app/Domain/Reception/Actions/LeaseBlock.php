<?php

declare(strict_types=1);

namespace App\Domain\Reception\Actions;

use App\Domain\Reception\Exceptions\SessionNotAtDeviceBranch;
use App\Domain\Serials\Actions\LeaseBlock as EngineLeaseBlock;
use App\Domain\Shared\Actor;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;

/**
 * OFFLINE §4.1 with the device model: the session must be at the device's branch; the engine (S) does the locked
 * carve with size = min(requested, device.block_size, serial.default_block_size, 30, remaining) and the 2-active limit.
 */
final class LeaseBlock
{
    public function __construct(private readonly EngineLeaseBlock $engine) {}

    public function handle(ReceptionDevice $device, SessionInstance $session, int $requested, Actor $actor): SerialBlock
    {
        if ($session->branch_id !== $device->branch_id) {
            throw new SessionNotAtDeviceBranch;
        }

        return $this->engine->handle($session, $device->id, max(1, $requested), $actor, $device->block_size);
    }
}
