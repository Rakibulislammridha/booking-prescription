<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Serials;

use App\Domain\Serials\Actions\CallNext;
use App\Domain\Serials\Actions\CancelSession;
use App\Domain\Serials\Actions\ChangePoolSplit;
use App\Domain\Serials\Actions\CloseSession;
use App\Domain\Serials\Actions\DelaySession;
use App\Domain\Serials\Actions\ExtendSessionCapacity;
use App\Domain\Serials\Actions\PauseSession;
use App\Domain\Serials\Actions\ReleaseOnlineToCounter;
use App\Domain\Serials\Actions\ResumeSession;
use App\Domain\Serials\Actions\StartSession;
use App\Domain\Serials\Actions\TransferSession;
use App\Domain\Serials\Services\CapacityService;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Serials\DelaySessionRequest;
use App\Http\Requests\Panel\Serials\ExtendSessionRequest;
use App\Http\Requests\Panel\Serials\ReleaseOnlineRequest;
use App\Http\Requests\Panel\Serials\SessionReasonRequest;
use App\Http\Requests\Panel\Serials\SplitPoolsRequest;
use App\Http\Requests\Panel\Serials\TransferSessionRequest;
use App\Http\Resources\Scheduling\SessionInstanceResource;
use App\Http\Resources\Serials\SerialBlockResource;
use App\Http\Resources\Serials\SerialResource;
use App\Models\Tenant\SerialPool;
use App\Models\Tenant\SessionInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The session-level JSON actions of SERIAL_ENGINE §16 (panel + api surfaces). */
final class SessionActionController extends Controller
{
    public function show(Request $request, SessionInstance $session, CapacityService $capacity): JsonResponse
    {
        $this->authorize('view', $session);
        $session->load(['doctor', 'branch', 'serials' => fn ($q) => $q->orderBy('position')->orderBy('number')]);
        $session->setAttribute('remaining', $capacity->remainingFor([$session->id])[$session->id] ?? CapacityService::empty());

        return response()->json(['session' => new SessionInstanceResource($session)]);
    }

    public function capacity(Request $request, SessionInstance $session, CapacityService $capacity): JsonResponse
    {
        $this->authorize('view', $session);

        return response()->json($capacity->remaining($session->id));
    }

    public function callNext(SessionReasonRequest $request, SessionInstance $session, CallNext $action): JsonResponse
    {
        $this->authorize('callNext', $session);
        $result = $action->handle($session, Actor::fromRequest($request));

        return response()->json(['called' => $result['called'] === null ? null : new SerialResource($result['called']->load('sessionInstance')), 'waiting_booked' => $result['waiting_booked']]);
    }

    public function start(SessionReasonRequest $request, SessionInstance $session, StartSession $action): JsonResponse
    {
        $this->authorize('start', $session);

        return $this->session($action->handle($session, Actor::fromRequest($request)));
    }

    public function pause(SessionReasonRequest $request, SessionInstance $session, PauseSession $action): JsonResponse
    {
        $this->authorize('pause', $session);

        return $this->session($action->handle($session, Actor::fromRequest($request), $request->validated('reason')));
    }

    public function resume(SessionReasonRequest $request, SessionInstance $session, ResumeSession $action): JsonResponse
    {
        $this->authorize('pause', $session);

        return $this->session($action->handle($session, Actor::fromRequest($request)));
    }

    public function close(SessionReasonRequest $request, SessionInstance $session, CloseSession $action): JsonResponse
    {
        $this->authorize('close', $session);

        return $this->session($action->handle($session, Actor::fromRequest($request), $request->validated('reason')));
    }

    public function cancel(SessionReasonRequest $request, SessionInstance $session, CancelSession $action): JsonResponse
    {
        $this->authorize('cancel', $session);

        return $this->session($action->handle($session, Actor::fromRequest($request), $request->validated('reason')));
    }

    public function delay(DelaySessionRequest $request, SessionInstance $session, DelaySession $action): JsonResponse
    {
        return $this->session($action->handle($session, (int) $request->validated('delay_minutes'), Actor::fromRequest($request), $request->validated('message')));
    }

    public function extend(ExtendSessionRequest $request, SessionInstance $session, ExtendSessionCapacity $action): JsonResponse
    {
        $updated = $action->handle($session, (int) $request->validated('extra'), Actor::fromRequest($request), $request->validated('reason'));

        return response()->json(['session' => new SessionInstanceResource($updated), 'pools' => $this->pools($updated)]);
    }

    public function releaseOnline(ReleaseOnlineRequest $request, SessionInstance $session, ReleaseOnlineToCounter $action): JsonResponse
    {
        $count = $request->validated('count');
        $result = $action->handle($session, $count === null ? null : (int) $count, Actor::fromRequest($request), $request->validated('reason'));

        return response()->json(['pools' => $this->pools($result['session']), 'released_block' => $result['released_block'] === null ? null : new SerialBlockResource($result['released_block'])]);
    }

    public function split(SplitPoolsRequest $request, SessionInstance $session, ChangePoolSplit $action): JsonResponse
    {
        $buffer = $request->validated('buffer_quota');
        $updated = $action->handle($session, (int) $request->validated('counter_quota'), (int) $request->validated('online_quota'), Actor::fromRequest($request), $buffer === null ? null : (int) $buffer);

        return response()->json(['session' => new SessionInstanceResource($updated), 'pools' => $this->pools($updated)]);
    }

    public function transfer(TransferSessionRequest $request, SessionInstance $session, TransferSession $action): JsonResponse
    {
        $target = SessionInstance::query()->where('public_id', (string) $request->validated('target_session'))->firstOrFail();

        return response()->json($action->handle($session, $target, Actor::fromRequest($request), $request->validated('reason')));
    }

    private function session(SessionInstance $session): JsonResponse
    {
        return response()->json(['session' => new SessionInstanceResource($session->load(['doctor', 'branch']))]);
    }

    /** @return array<string, array{range_start: int, range_end: int, next_number: int, remaining: int}> */
    private function pools(SessionInstance $session): array
    {
        $out = [];

        foreach (SerialPool::query()->where('session_instance_id', $session->id)->orderBy('pool')->get() as $pool) {
            $out[$pool->pool->value] = ['range_start' => $pool->range_start, 'range_end' => $pool->range_end, 'next_number' => $pool->next_number, 'remaining' => $pool->remaining()];
        }

        return $out;
    }
}
