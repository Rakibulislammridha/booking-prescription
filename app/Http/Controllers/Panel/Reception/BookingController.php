<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Reception;

use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Exceptions\PatientAmbiguous;
use App\Domain\Reception\Services\SerialPresenter;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Reception\StoreCounterBookingRequest;
use App\Http\Resources\Booking\AppointmentResource;
use App\Http\Resources\Patients\FamilyMemberResource;
use Illuminate\Http\JsonResponse;

/** POST /panel/reception/bookings — the desk's one-click booking (phone/counter/walk-in/follow-up) → 201 with the slip data. */
final class BookingController extends Controller
{
    public function store(StoreCounterBookingRequest $request, BookAppointment $book, SerialPresenter $presenter): JsonResponse
    {
        try {
            $result = $book->handle($request->toData(), Actor::fromRequest($request));
        } catch (PatientAmbiguous $e) {
            return response()->json([
                'message' => $e->getMessage(), 'code' => $e->code(),
                'household' => FamilyMemberResource::collection($e->household)->resolve(),
            ], $e->status());
        }

        return response()->json([
            'appointment' => (new AppointmentResource($result->appointment->load(['patient', 'doctor', 'sessionInstance'])))->toArray($request),
            'serial' => $presenter->present($result->serial, $result->patient, $result->appointment),
            'patient_created' => $result->patientCreated,
            'replayed' => $result->replayed,
        ], $result->replayed ? 200 : 201);
    }
}
