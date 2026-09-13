<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reception;

use App\Domain\Clinic\Services\DoctorScope;
use App\Domain\Reception\Enums\OfflineEventStatus;
use App\Domain\Reception\Sync\SyncReplayer;
use App\Http\Controllers\Api\Reception\Concerns\ResolvesDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Reception\ResolveConflictRequest;
use App\Http\Requests\Api\Reception\SyncRequest;
use App\Models\Tenant\OfflineEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** OFFLINE §7: POST /sync (replay a batch), POST /sync/resolve (a decision), GET /sync/conflicts (rehydrate cards). */
final class SyncController extends Controller
{
    use ResolvesDevice;

    public function store(SyncRequest $request, SyncReplayer $replayer): JsonResponse
    {
        $result = $replayer->replay($this->device($request), $this->actorUser($request), $request->events(), $request->validated('app_version'));

        return response()->json($result->toArray());
    }

    public function resolve(ResolveConflictRequest $request, SyncReplayer $replayer): JsonResponse
    {
        $result = $replayer->resolve($this->device($request), $this->actorUser($request), strtoupper((string) $request->validated('client_event_id')), $request->toData());

        return response()->json($result);
    }

    /**
     * The cards to rehydrate after the device's Dexie log is gone (OFFLINE §7.4). The rows carry their whole
     * `payload`, and a `register_patient` payload is a patient's name, mobile, sex and age — so a device-only filter
     * hands every actor who signs in at a shared desk tablet everything every other actor typed into it.
     *
     * The filter is the ACTOR's boundary, not the actor's identity: a restricted user (DoctorScope answers a list —
     * today, a compounder) sees only the events they themselves queued, because a stub names no doctor and there is
     * no other way to prove one of those payloads is theirs to read. Everyone else keeps the whole device's list,
     * which is the point of it: the evening receptionist has to be able to finish the cards the morning one left
     * open, and hiding them would strand the events rather than protect anybody — those actors already read the
     * clinic's patients on the panel.
     */
    public function conflicts(Request $request, DoctorScope $scope): JsonResponse
    {
        $actor = $this->actorUser($request);
        $restricted = $scope->doctorIds($actor) !== null;

        $rows = OfflineEvent::query()
            ->where('reception_device_id', $this->device($request)->id)
            ->when($restricted, fn (Builder $q) => $q->where('actor_user_id', $actor->id))
            ->whereIn('status', [OfflineEventStatus::Conflict->value, OfflineEventStatus::Pending->value])
            ->orderBy('sequence_no')
            ->limit(SyncReplayer::MAX_BATCH)
            ->get();

        return response()->json(['events' => $rows->map(fn (OfflineEvent $e) => $e->toResult() + ['type' => $e->type->value, 'sequence_no' => $e->sequence_no, 'payload' => $e->payload, 'depends_on' => $e->depends_on])->values()->all()]);
    }
}
