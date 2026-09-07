<?php

declare(strict_types=1);

namespace App\Domain\Reception\Http\Middleware;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Reception\Enums\DeviceKind;
use App\Domain\Reception\Exceptions\ActorNotPermitted;
use App\Domain\Reception\Exceptions\DeviceNotActive;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * OFFLINE §2.2 — every /api/reception/* route except registration:
 *  1. the bearer token must belong to an active reception device of the request tenant and carry the route's ability
 *     (`device:<ability>` middleware parameter; the guard's session fallback is deliberately NOT used — a staff cookie
 *     travelling next to the device token must never masquerade as the device);
 *  2. X-Actor-User must be an active Receptionist / Hospital Admin at the device's branch → attached as `actor`;
 *  3. last_seen_at / last_ip / app_version updated, throttled to once a minute.
 */
final class AuthenticateReceptionDevice
{
    public const ACTOR_HEADER = 'X-Actor-User';

    public const VERSION_HEADER = 'X-App-Version';

    public function handle(Request $request, Closure $next, string $ability = 'reception:read'): Response
    {
        $device = $this->device($request);

        if ($device === null || ! $device->isActive() || $device->kind !== DeviceKind::Reception) {
            throw new DeviceNotActive($device === null ? 'unauthenticated' : 'revoked');
        }

        if (! $device->tokenCan($ability)) {
            throw new DeviceNotActive('ability');
        }

        $actor = $this->actor($request, $device);

        auth('device')->setUser($device);
        $request->attributes->set('device', $device);
        $request->attributes->set('actor', $actor);

        $this->touch($device, $request);

        return $next($request);
    }

    private function device(Request $request): ?ReceptionDevice
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || $bearer === '' || ! Tenancy::check()) {
            return null;
        }

        $model = Sanctum::$personalAccessTokenModel;
        $token = $model::findToken($bearer);

        if ($token === null || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return null;
        }

        $tokenable = $token->tokenable;

        if (! $tokenable instanceof ReceptionDevice) {
            return null;
        }

        return $tokenable->withAccessToken($token);
    }

    private function actor(Request $request, ReceptionDevice $device): User
    {
        $publicId = (string) $request->header(self::ACTOR_HEADER, '');

        if ($publicId === '') {
            throw new ActorNotPermitted('missing');
        }

        $user = User::query()->where('public_id', $publicId)->first();

        if ($user === null || ! $user->is_active) {
            throw new ActorNotPermitted('inactive');
        }

        $admin = $user->hasRole(Role::HospitalAdmin->value);

        if (! $admin && ! $user->hasRole(Role::Receptionist->value)) {
            throw new ActorNotPermitted('role');
        }

        if (! $admin && $user->default_branch_id !== $device->branch_id) {
            throw new ActorNotPermitted('branch');
        }

        return $user;
    }

    private function touch(ReceptionDevice $device, Request $request): void
    {
        if ($device->last_seen_at !== null && $device->last_seen_at->gt(now()->subMinute())) {
            return;
        }

        $device->forceFill(array_filter([
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
            'app_version' => $request->header(self::VERSION_HEADER),
        ], fn ($v) => $v !== null))->save();
    }
}
