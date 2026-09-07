<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Reception;

use App\Domain\Booking\Actions\CancelAppointment;
use App\Domain\Booking\Actions\RescheduleAppointment;
use App\Domain\Reception\Actions\CollectFee;
use App\Domain\Reception\Services\SerialPresenter;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Reception\CancelAppointmentRequest;
use App\Http\Requests\Panel\Reception\CollectFeeRequest;
use App\Http\Requests\Panel\Reception\RescheduleAppointmentRequest;
use App\Http\Resources\Booking\AppointmentResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\SessionInstance;
use Illuminate\Http\JsonResponse;

/** Desk actions on a booking: collect fee (CashCollector), cancel with a reason code (refund via contract), reschedule. */
final class AppointmentController extends Controller
{
    public function collect(CollectFeeRequest $request, Appointment $appointment, CollectFee $action): JsonResponse
    {
        $amount = $request->validated('amount_paisa');
        $collection = $action->handle($appointment, $amount === null ? null : (int) $amount, Actor::fromRequest($request), null, $request->validated('note'));

        return response()->json(['payment' => $collection->toArray(), 'appointment' => new AppointmentResource($appointment->refresh())]);
    }

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment, CancelAppointment $action, SerialPresenter $presenter): JsonResponse
    {
        $result = $action->handle($appointment, CancelReason::from((string) $request->validated('reason_code')), Actor::fromRequest($request), $request->validated('note'));
        $serial = $result['appointment']->serial;

        return response()->json([
            'appointment' => new AppointmentResource($result['appointment']),
            'serial' => $serial === null ? null : $presenter->present($serial, null, $result['appointment']),
            'refund_eligible' => $result['refund_eligible'],
            'refund' => $result['refund'],
        ]);
    }

    public function reschedule(RescheduleAppointmentRequest $request, Appointment $appointment, RescheduleAppointment $action, SerialPresenter $presenter): JsonResponse
    {
        $target = SessionInstance::query()->where('public_id', (string) $request->validated('target_session'))->firstOrFail();
        $result = $action->handle($appointment, $target, Actor::fromRequest($request), $request->validated('reason'));

        return response()->json([
            'appointment' => new AppointmentResource($result['appointment']->load(['sessionInstance', 'doctor'])),
            'old' => $presenter->present($result['old']),
            'new' => $presenter->present($result['new'], null, $result['appointment']),
        ]);
    }
}
