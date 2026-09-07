<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Queue;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Queue\Services\BoardStateBuilder;
use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Queue\TenantChannel;
use App\Domain\Reception\Enums\DeviceKind;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The waiting-room display — `GET /display/{branchSlug}?token=…&kiosk=1` (`site.queue.display`, REALTIME.md §9).
 *
 * Auth is one of: a `reception_devices` row of kind `display` at that branch (its Sanctum token in `?token=`, which
 * the page then hands to Echo as `bearerToken` for the private display channel), a signed URL (the desk generates
 * one for a TV without a device row), or a logged-in staff user previewing the board.
 */
final class DisplayPageController extends Controller
{
    public function __invoke(Request $request, string $branchSlug, BoardStateBuilder $tiles, QueueStateRepository $repository, Settings $settings): Response
    {
        $branch = Branch::query()->active()->where('slug', $branchSlug)->firstOrFail();
        $device = self::device($request, $branch);

        if ($device === null && ! $request->hasValidSignature() && ! $request->user('web') instanceof User) {
            throw new AccessDeniedHttpException('queue.display_not_authorised');
        }

        $tenant = Tenancy::current();
        $rows = $tiles->tiles($branch);
        $states = [];

        foreach ($rows as $row) {
            $session = SessionInstance::query()->with(['doctor', 'branch'])->where('public_id', $row['id'])->first();

            if ($session !== null) {
                $states[$session->public_id] = $repository->state($session);
            }
        }

        return Inertia::render('Display/Board', [
            'tenant_public_id' => $tenant?->public_id,
            'channel' => $tenant === null ? null : TenantChannel::displayName($tenant->public_id, $branch->public_id),
            'branch' => ['public_id' => $branch->public_id, 'slug' => $branch->slug, 'name' => $branch->name],
            'date' => Clock::today()->toDateString(),
            'tiles' => $rows,
            'states' => $states,
            'device_token' => $device === null ? null : (string) $request->query('token'),
            'kiosk' => $request->boolean('kiosk'),
            'voice' => (string) $settings->get('queue.display_voice'),
        ]);
    }

    private static function device(Request $request, Branch $branch): ?ReceptionDevice
    {
        $token = (string) $request->query('token', '');

        if ($token === '' || ! Tenancy::check()) {
            return null;
        }

        $model = Sanctum::$personalAccessTokenModel;
        $row = $model::findToken($token);

        if ($row === null || ($row->expires_at !== null && $row->expires_at->isPast())) {
            return null;
        }

        $device = $row->tokenable;

        return $device instanceof ReceptionDevice && $device->isActive() && $device->kind === DeviceKind::Display && $device->branch_id === $branch->id
            ? $device
            : null;
    }
}
