<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reception;

use App\Domain\Reception\Actions\RegisterReceptionDevice;
use App\Domain\Reception\Actions\RevokeReceptionDevice;
use App\Domain\Reception\Enums\DeviceKind;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Api\Reception\Concerns\ResolvesDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Reception\RegisterDeviceRequest;
use App\Http\Resources\Reception\ReceptionDeviceResource;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ReceptionDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** OFFLINE §2.1 registration (staff session) and §2.2 revocation (device guard, Hospital Admin actor). */
final class DeviceController extends Controller
{
    use ResolvesDevice;

    public function register(RegisterDeviceRequest $request, RegisterReceptionDevice $action): JsonResponse
    {
        $branch = Branch::query()->active()->where('public_id', (string) $request->validated('branch'))->firstOrFail();
        $result = $action->handle(
            $branch,
            (string) $request->validated('name'),
            (string) $request->validated('device_fingerprint'),
            DeviceKind::from((string) $request->validated('kind', DeviceKind::Reception->value)),
            Actor::fromRequest($request),
            $request->validated('app_version'),
            $request->userAgent(),
            $request->validated('block_size') === null ? null : (int) $request->validated('block_size'),
        );

        return response()->json([
            'device' => (new ReceptionDeviceResource($result['device']->load('branch')))->toArray($request),
            'token' => $result['token']->plainTextToken,
            'abilities' => ReceptionDevice::ABILITIES,
            'expires_at' => $result['token']->accessToken->expires_at?->toIso8601ZuluString(),
            'rotated' => $result['rotated'],
        ], 201);
    }

    public function revoke(Request $request, ReceptionDevice $device, RevokeReceptionDevice $action): JsonResponse
    {
        $actor = $this->actorUser($request);
        abort_unless($actor->can('revoke', $device), 403);

        $revoked = $action->handle($device, $this->actor($request), (string) $request->input('reason', 'revoked'));

        return response()->json(['device' => new ReceptionDeviceResource($revoked)]);
    }
}
