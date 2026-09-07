<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Queue;

use App\Domain\Queue\Services\BoardStateBuilder;
use App\Domain\Reception\Enums\DeviceKind;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\User;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * `GET /api/queue/display/{branch}` (`api.queue.display.tiles`) — the display's 10-minute self-heal (REALTIME.md
 * §9.3): the tiles for a branch, so a newly materialised session appears on the TV without a page reload.
 * Authenticated as a `display` device of that branch or as a staff user.
 */
final class DisplayBoardController extends Controller
{
    public function __invoke(Request $request, Branch $branch, BoardStateBuilder $tiles): JsonResponse
    {
        $actor = $request->user('device') ?? $request->user('sanctum') ?? $request->user('web');

        $allowed = $actor instanceof User
            ? $actor->is_active
            : $actor instanceof ReceptionDevice && $actor->isActive() && $actor->kind === DeviceKind::Display && $actor->branch_id === $branch->id;

        if (! $allowed) {
            throw new AccessDeniedHttpException('queue.display_not_authorised');
        }

        return response()->json([
            'branch' => ['public_id' => $branch->public_id, 'slug' => $branch->slug, 'name' => $branch->name],
            'date' => Clock::today()->toDateString(),
            'tiles' => $tiles->tiles($branch),
        ])->header('Cache-Control', 'no-store');
    }
}
