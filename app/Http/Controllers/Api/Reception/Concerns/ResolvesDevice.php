<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reception\Concerns;

use App\Domain\Shared\Actor;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\User;
use Illuminate\Http\Request;

/** The device and the human actor that AuthenticateReceptionDevice attached to the request (OFFLINE §2.2). */
trait ResolvesDevice
{
    protected function device(Request $request): ReceptionDevice
    {
        $device = $request->attributes->get('device');

        return $device instanceof ReceptionDevice ? $device : abort(401);
    }

    protected function actorUser(Request $request): User
    {
        $actor = $request->attributes->get('actor');

        return $actor instanceof User ? $actor : abort(403);
    }

    protected function actor(Request $request): Actor
    {
        $user = $this->actorUser($request);
        $role = $user->getRoleNames()->first();

        return new Actor(userId: $user->id, role: $role === null ? null : (string) $role, deviceId: $this->device($request)->id, ip: $request->ip(), source: 'api');
    }
}
