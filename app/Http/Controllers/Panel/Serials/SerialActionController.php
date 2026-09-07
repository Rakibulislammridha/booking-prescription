<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Serials;

use App\Domain\Serials\Actions\CallSerial;
use App\Domain\Serials\Actions\CancelSerial;
use App\Domain\Serials\Actions\CheckInSerial;
use App\Domain\Serials\Actions\CompleteConsultation;
use App\Domain\Serials\Actions\MarkNoShow;
use App\Domain\Serials\Actions\PostponeSerial;
use App\Domain\Serials\Actions\PriorityInsert;
use App\Domain\Serials\Actions\ReinstateSerial;
use App\Domain\Serials\Actions\ReorderSerial;
use App\Domain\Serials\Actions\ReturnToQueue;
use App\Domain\Serials\Actions\SkipCalled;
use App\Domain\Serials\Actions\StartConsultation;
use App\Domain\Serials\Actions\TransferSerial;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Serials\CancelSerialRequest;
use App\Http\Requests\Panel\Serials\PostponeSerialRequest;
use App\Http\Requests\Panel\Serials\PrioritySerialRequest;
use App\Http\Requests\Panel\Serials\ReinstateSerialRequest;
use App\Http\Requests\Panel\Serials\ReorderSerialRequest;
use App\Http\Requests\Panel\Serials\SerialReasonRequest;
use App\Http\Requests\Panel\Serials\TransferSerialRequest;
use App\Http\Resources\Serials\SerialResource;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Http\JsonResponse;

/**
 * The serial-level JSON actions of SERIAL_ENGINE §16 (panel + api surfaces share this controller). Domain failures
 * surface as {message, code} with the exception's status through the global renderer.
 */
final class SerialActionController extends Controller
{
    public function checkIn(SerialReasonRequest $request, Serial $serial, CheckInSerial $action): JsonResponse
    {
        $this->authorize('checkIn', $serial);

        return $this->serial($action->handle($serial, Actor::fromRequest($request), $request->validated('client_event_id')));
    }

    public function call(SerialReasonRequest $request, Serial $serial, CallSerial $action): JsonResponse
    {
        $this->authorize('call', $serial);

        return $this->serial($action->handle($serial, Actor::fromRequest($request)));
    }

    public function start(SerialReasonRequest $request, Serial $serial, StartConsultation $action): JsonResponse
    {
        $this->authorize('complete', $serial);

        return $this->serial($action->handle($serial, Actor::fromRequest($request)));
    }

    public function complete(SerialReasonRequest $request, Serial $serial, CompleteConsultation $action): JsonResponse
    {
        $this->authorize('complete', $serial);

        return $this->serial($action->handle($serial, Actor::fromRequest($request)));
    }

    public function skip(SerialReasonRequest $request, Serial $serial, SkipCalled $action): JsonResponse
    {
        $this->authorize('call', $serial);

        return $this->serial($action->handle($serial, Actor::fromRequest($request)));
    }

    public function return(SerialReasonRequest $request, Serial $serial, ReturnToQueue $action): JsonResponse
    {
        $this->authorize('call', $serial);

        return $this->serial($action->handle($serial, Actor::fromRequest($request), $request->validated('reason')));
    }

    public function noShow(SerialReasonRequest $request, Serial $serial, MarkNoShow $action): JsonResponse
    {
        $this->authorize('noShow', $serial);

        return $this->serial($action->handle($serial, Actor::fromRequest($request), $request->validated('reason')));
    }

    public function reinstate(ReinstateSerialRequest $request, Serial $serial, ReinstateSerial $action): JsonResponse
    {
        return $this->serial($action->handle($serial, Actor::fromRequest($request), (bool) ($request->validated('present') ?? true)));
    }

    public function cancel(CancelSerialRequest $request, Serial $serial, CancelSerial $action): JsonResponse
    {
        $result = $action->handle($serial, CancelReason::from((string) $request->validated('reason_code')), Actor::fromRequest($request), $request->validated('note'));

        return response()->json(['serial' => new SerialResource($result['serial']->load('sessionInstance')), 'refund_eligible' => $result['refund_eligible']]);
    }

    public function postpone(PostponeSerialRequest $request, Serial $serial, PostponeSerial $action): JsonResponse
    {
        $target = $request->validated('target_session');
        $result = $action->handle($serial, $target === null ? null : SessionInstance::query()->where('public_id', $target)->firstOrFail(), Actor::fromRequest($request), $request->validated('reason'));

        return response()->json(['old' => new SerialResource($result['old']->load('sessionInstance')), 'new' => new SerialResource($result['new']->load('sessionInstance'))]);
    }

    public function transfer(TransferSerialRequest $request, Serial $serial, TransferSerial $action): JsonResponse
    {
        $target = SessionInstance::query()->where('public_id', (string) $request->validated('target_session'))->firstOrFail();
        $result = $action->handle($serial, $target, Actor::fromRequest($request), $request->validated('reason'));

        return response()->json(['old' => new SerialResource($result['old']->load('sessionInstance')), 'new' => new SerialResource($result['new']->load('sessionInstance')), 'fee_delta_expected' => $result['fee_delta_expected']]);
    }

    public function reorder(ReorderSerialRequest $request, Serial $serial, ReorderSerial $action): JsonResponse
    {
        $after = $request->validated('after');
        $before = $request->validated('before');

        return $this->serial($action->handle(
            $serial,
            $after === null ? null : (int) Serial::query()->where('public_id', $after)->value('id'),
            $before === null ? null : (int) Serial::query()->where('public_id', $before)->value('id'),
            Actor::fromRequest($request),
            $request->validated('reason'),
        ));
    }

    public function priority(PrioritySerialRequest $request, Serial $serial, PriorityInsert $action): JsonResponse
    {
        return $this->serial($action->handle($serial, SerialPriority::from((string) $request->validated('priority')), Actor::fromRequest($request), $request->validated('reason')));
    }

    private function serial(Serial $serial): JsonResponse
    {
        return response()->json(['serial' => new SerialResource($serial->load('sessionInstance'))]);
    }
}
