<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reception;

use App\Domain\Clinic\Services\DoctorScope;
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
use Illuminate\Support\Facades\Gate;

/**
 * OFFLINE §4: lease / list / release / revoke of this device's blocks (ability reception:blocks).
 *
 * A block is a slice of ONE doctor's serial numbers handed to a device to spend while the line is down, so leasing
 * one is the same authority as issuing at the counter and is authorised the same way: `issue` on the target session,
 * for the ACTOR the device names (X-Actor-User), never for the device. Without it a shared desk tablet was a way
 * around every conjunct on the panel — "he can't be able to edit the serial number" cannot be true of a role that
 * can lease itself a block of them on any chamber in the building.
 *
 * `index` narrows the same way, and for a second reason: the list is written into the PWA's Dexie cache and is what
 * the offline desk believes it may issue from. Handing a restricted actor numbers the replay will only reject turns
 * a refusal at the door into a slip already in a patient's hand.
 *
 * `release` needs no scope conjunct (the action refuses a block that is not this device's) and neither does
 * `revoke`: `reception.blocks.revoke` is a Hospital Admin permission, and a hospital admin is unrestricted by
 * DoctorScope's own rule.
 */
final class BlockController extends Controller
{
    use ResolvesDevice;

    public function index(Request $request, CapacityService $capacity, DoctorScope $scope): JsonResponse
    {
        $device = $this->device($request);
        $date = (string) $request->query('date', '');
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::parse($date, Clock::timezone())->startOfDay() : Clock::today();

        $doctorIds = $scope->doctorIds($this->actorUser($request));

        $blocks = SerialBlock::query()
            ->where('reception_device_id', $device->id)
            ->whereHas('sessionInstance', fn ($q) => $q->whereDate('session_date', '>=', $day->toDateString())
                ->when($doctorIds !== null, fn ($d) => $d->whereIn('doctor_id', $doctorIds ?? [])))
            ->with('sessionInstance')
            ->orderBy('range_start')
            ->get();

        return response()->json(['blocks' => $blocks->map(fn (SerialBlock $b) => $this->block($b, $request))->values()->all()]);
    }

    public function lease(LeaseBlockRequest $request, LeaseBlock $action, CapacityService $capacity): JsonResponse
    {
        $session = SessionInstance::query()->where('public_id', (string) $request->validated('session'))->firstOrFail();
        Gate::forUser($this->actorUser($request))->authorize('issue', $session);

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
