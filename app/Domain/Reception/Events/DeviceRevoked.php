<?php

declare(strict_types=1);

namespace App\Domain\Reception\Events;

use App\Models\Tenant\ReceptionDevice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class DeviceRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public readonly int $deviceId;

    public readonly string $devicePublicId;

    public function __construct(ReceptionDevice $device, public readonly int $blocksRevoked)
    {
        $this->deviceId = $device->id;
        $this->devicePublicId = $device->public_id;
    }
}
