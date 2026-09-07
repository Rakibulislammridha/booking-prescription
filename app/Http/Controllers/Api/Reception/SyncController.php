<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reception;

use App\Domain\Reception\Enums\OfflineEventStatus;
use App\Domain\Reception\Sync\SyncReplayer;
use App\Http\Controllers\Api\Reception\Concerns\ResolvesDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Reception\ResolveConflictRequest;
use App\Http\Requests\Api\Reception\SyncRequest;
use App\Models\Tenant\OfflineEvent;
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

    public function conflicts(Request $request): JsonResponse
    {
        $rows = OfflineEvent::query()
            ->where('reception_device_id', $this->device($request)->id)
            ->whereIn('status', [OfflineEventStatus::Conflict->value, OfflineEventStatus::Pending->value])
            ->orderBy('sequence_no')
            ->limit(SyncReplayer::MAX_BATCH)
            ->get();

        return response()->json(['events' => $rows->map(fn (OfflineEvent $e) => $e->toResult() + ['type' => $e->type->value, 'sequence_no' => $e->sequence_no, 'payload' => $e->payload, 'depends_on' => $e->depends_on])->values()->all()]);
    }
}
