<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reception;

use App\Domain\Reception\Actions\LeaseBlock;
use App\Domain\Reception\Actions\ReleaseBlock;
use App\Domain\Reception\Actions\RevokeBlock;
use App\Domain\Serials\Services\CapacityService;
use App\Http\Controllers\Api\Reception\Concerns\ResolvesDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Reception\LeaseBlockRequest;
use App\Http\Resources\Serials\SerialBlockResource;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** OFFLINE §4: lease / list / release / revoke of this device's blocks (ability reception:blocks). */
final class BlockController extends Controller
{
    use ResolvesDevice;

    public function index(Request $request, CapacityService $capacity): JsonResponse
    {
        $device = $this->device($request);
        $date = (string) $request->query('date', '');
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::parse($date, Clock::timezone())->startOfDay() : Clock::today();

        $blocks = SerialBlock::query()
            ->where('reception_device_id', $device->id)
            ->whereHas('sessionInstance', fn ($q) => $q->whereDate('session_date', '>=', $day->toDateString()))
            ->with('sessionInstance')
            ->orderBy('range_start')
            ->get();

        return response()->json(['blocks' => $blocks->map(fn (SerialBlock $b) => $this->block($b, $request))->values()->all()]);
    }

    public function lease(LeaseBlockRequest $request, LeaseBlock $action, CapacityService $capacity): JsonResponse
    {
        $session = SessionInstance::query()->where('public_id', (string) $request->validated('session'))->firstOrFail();
        $block = $action->handle($this->device($request), $session, (int) $request->validated('size', 10), $this->actor($request));
        $remaining = $capacity->remainingFor([$session->id])[$session->id] ?? CapacityService::empty();

        return response()->json([
            'block' => $this->block($block->load('sessionInstance'), $request),
            'pool_remaining' => ['counter' => $remaining['counter'], 'released' => $remaining['released']],
        ], 201);
    }

    public function release(Request $request, SerialBlock $block, ReleaseBlock $action): JsonResponse
    {
        $result = $action->handle($this->device($request), $block, $this->actor($request), (string) $request->input('reason', 'device_release'));

        return response()->json(['released_unused' => $result['released_unused'], 'block' => $this->block($result['block']->load('sessionInstance'), $request)]);
    }

    public function revoke(Request $request, SerialBlock $block, RevokeBlock $action): JsonResponse
    {
        abort_unless($this->actorUser($request)->can('reception.blocks.revoke'), 403);
        $revoked = $action->handle($block, $this->actor($request), (string) $request->input('reason', 'revoked'));

        return response()->json(['block' => $this->block($revoked->load('sessionInstance'), $request)]);
    }

    /** @return array<string, mixed> */
    private function block(SerialBlock $block, Request $request): array
    {
        return (new SerialBlockResource($block))->toArray($request) + [
            'session' => $block->sessionInstance->public_id,
            'session_code' => $block->sessionInstance->session_code,
            'date' => $block->sessionInstance->session_date->toDateString(),
        ];
    }
}
