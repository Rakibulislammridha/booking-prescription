<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Reception;

use App\Domain\Reception\Actions\RevokeReceptionDevice;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Resources\Reception\ReceptionDeviceResource;
use App\Models\Tenant\ReceptionDevice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Reception/Devices: the registered tablets of the tenant with revoke (Hospital Admin). */
final class DeviceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ReceptionDevice::class);
        $devices = ReceptionDevice::query()->with('branch')->orderBy('branch_id')->orderBy('number')->get();

        return Inertia::render('Reception/Devices', [
            'devices' => ReceptionDeviceResource::collection($devices)->resolve(),
            'can' => ['revoke' => $request->user('web')?->can('reception.blocks.revoke') ?? false],
        ]);
    }

    public function revoke(Request $request, ReceptionDevice $device, RevokeReceptionDevice $action): RedirectResponse
    {
        $this->authorize('revoke', $device);
        $action->handle($device, Actor::fromRequest($request), (string) $request->input('reason', 'revoked'));

        return redirect()->route('panel.reception.devices.index')->with('flash.success', __('reception.devices.revoked'));
    }
}
