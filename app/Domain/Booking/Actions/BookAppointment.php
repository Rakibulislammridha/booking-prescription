<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Data\BookingRequest;
use App\Domain\Booking\Data\BookingResult;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Exceptions\AlreadyBooked;
use App\Domain\Booking\Exceptions\DoctorNotBookable;
use App\Domain\Booking\Exceptions\OtpRequired;
use App\Domain\Booking\Exceptions\PatientAmbiguous;
use App\Domain\Booking\Exceptions\PatientRequired;
use App\Domain\Booking\Exceptions\SessionNotFound;
use App\Domain\Booking\Services\AppointmentWriter;
use App\Domain\Booking\Services\FeeResolver;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Patients\Actions\FindOrCreatePatientByMobile;
use App\Domain\Patients\Data\PatientLookup;
use App\Domain\Patients\Enums\PatientSource;
use App\Domain\Scheduling\Services\SessionMaterialiser;
use App\Domain\Serials\Actions\AllocateSerial;
use App\Domain\Serials\Data\AllocationRequest;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * The LOCKED booking flow (BRIEF §5.C) for every online channel — online site, phone/counter, walk-in (buffer),
 * kiosk/QR (online pool), follow-up rebooking: mobile → FindOrCreatePatientByMobile → doctor/date/session
 * (materialised on demand) → AllocateSerial with the channel's pool/source → fee snapshot (FeeResolver) →
 * appointment row linked both ways → AppointmentBooked after commit. Online and kiosk never draw from the counter
 * pool (BookingChannel::pool). Idempotent by `clientEventId` (an online double submit returns the same booking).
 */
final class BookAppointment
{
    public function __construct(
        private readonly FindOrCreatePatientByMobile $findOrCreate,
        private readonly SessionMaterialiser $materialiser,
        private readonly AllocateSerial $allocate,
        private readonly FeeResolver $fees,
        private readonly AppointmentWriter $writer,
        private readonly Settings $settings,
    ) {}

    public function handle(BookingRequest $r, Actor $actor): BookingResult
    {
        $session = $this->resolveSession($r);
        /** @var Doctor $doctor */
        $doctor = Doctor::query()->with('profile')->findOrFail($session->doctor_id);
        $this->guardChannel($r, $doctor);

        // Idempotency (cheap, outside the transaction): the same client_event_id in the same session is the same booking.
        $replayed = $this->replay($r, $session);

        if ($replayed !== null) {
            return $replayed;
        }

        [$patient, $created] = $this->resolvePatient($r, $session, $actor);
        $previous = $r->followUpOfAppointmentId === null ? null : Appointment::query()->find($r->followUpOfAppointmentId);

        return DB::transaction(function () use ($r, $actor, $session, $doctor, $patient, $created, $previous): BookingResult {
            $live = Appointment::query()->where('patient_id', $patient->id)->where('session_instance_id', $session->id)->live()->first();

            if ($live !== null) {
                throw new AlreadyBooked($live->load('serial'));
            }

            $fee = $this->fees->resolve($patient, $doctor, $session, $r->channel, $r->type, $r->feeOverride, $previous);

            $serial = ($this->allocate)(new AllocationRequest(
                sessionInstanceId: $session->id,
                pool: $r->channel->pool(staffActor: $actor->userId !== null),
                source: $r->channel->serialSource(),
                priority: $r->priority,
                patientId: $patient->id,
                clientEventId: $r->clientEventId,
                actorUserId: $actor->userId,
                slotStartAt: $r->slotStartAt,
                priorityReason: $r->priorityReason,
            ));

            // A double submit that raced the pre-check: AllocateSerial handed back the existing serial with its appointment.
            if ($serial->appointment_id !== null) {
                /** @var Appointment $existing */
                $existing = Appointment::query()->findOrFail($serial->appointment_id);

                return new BookingResult($existing, $serial, $patient, false, $fee, replayed: true);
            }

            $appointment = $this->writer->createForSerial($serial, $patient, $session, $r->channel, $fee, $actor, [
                'notes' => $r->notes,
                'idempotency_key' => $r->clientEventId,
                'is_telemedicine' => $r->isTelemedicine || $r->channel === BookingChannel::Telemedicine,
            ]);

            return new BookingResult($appointment, $serial->refresh(), $patient, $created, $fee);
        });
    }

    private function replay(BookingRequest $r, SessionInstance $session): ?BookingResult
    {
        if ($r->clientEventId === null) {
            return null;
        }

        $existing = Appointment::query()->where('session_instance_id', $session->id)->where('client_event_id', $r->clientEventId)->with(['serial', 'patient', 'doctor.profile'])->first();

        if ($existing === null || $existing->serial === null) {
            return null;
        }

        $fee = $this->fees->resolve($existing->patient, $existing->doctor, $session, $existing->channel, $existing->type);

        return new BookingResult($existing, $existing->serial, $existing->patient, false, $fee, replayed: true);
    }

    /** @throws SessionNotFound */
    private function resolveSession(BookingRequest $r): SessionInstance
    {
        if ($r->sessionPublicId !== null) {
            return SessionInstance::query()->where('public_id', $r->sessionPublicId)->first() ?? throw new SessionNotFound;
        }

        if ($r->doctorSlug === null || $r->date === null || $r->sessionCode === null) {
            throw new SessionNotFound;
        }

        $doctor = Doctor::query()->active()->where('slug', $r->doctorSlug)->first() ?? throw new SessionNotFound;
        $branchId = $r->branchId ?? (int) Branch::query()->active()->orderByDesc('is_main')->orderBy('id')->value('id');

        return $this->materialiser->ensure($branchId, $doctor->id, $r->date, strtoupper($r->sessionCode)) ?? throw new SessionNotFound;
    }

    /** Self-service channels need an online-bookable doctor and (when the tenant requires it) a verified OTP. */
    private function guardChannel(BookingRequest $r, Doctor $doctor): void
    {
        if (! $r->channel->isSelfService()) {
            return;
        }

        if (! $doctor->is_active || ! $doctor->accepts_online_booking) {
            throw new DoctorNotBookable;
        }

        if ((bool) $this->settings->get('kiosk.otp_required') && ! $r->otpVerified) {
            throw new OtpRequired;
        }
    }

    /** @return array{0: Patient, 1: bool} */
    private function resolvePatient(BookingRequest $r, SessionInstance $session, Actor $actor): array
    {
        if ($r->patientPublicId !== null) {
            $patient = Patient::query()->where('public_id', $r->patientPublicId)->first() ?? throw new PatientRequired;

            return [$patient, false];
        }

        if ($r->mobile === null || trim($r->mobile) === '') {
            throw new PatientRequired;
        }

        $match = ($this->findOrCreate)(new PatientLookup(
            mobile: $r->mobile,
            name: $r->name,
            dob: $r->dob,
            ageYears: $r->ageYears,
            gender: $r->gender,
            source: match ($r->channel) {
                BookingChannel::Online, BookingChannel::Telemedicine => PatientSource::Online,
                BookingChannel::Kiosk => PatientSource::Kiosk,
                BookingChannel::Walkin => PatientSource::Walkin,
                default => PatientSource::Counter,
            },
            registeredBranchId: $session->branch_id,
            registeredByUserId: $actor->userId,
            relation: $r->relation,
        ));

        if ($match->patient === null) {
            throw $match->ambiguous || $match->household->isNotEmpty() ? new PatientAmbiguous($match->household) : new PatientRequired;
        }

        // No name given and several people share the number: the caller must pick the person (BRIEF §5.C household).
        if ($match->ambiguous && ($r->name === null || trim($r->name) === '')) {
            throw new PatientAmbiguous($match->household);
        }

        return [$match->patient, $match->created];
    }

    /** Today's clinic date — exposed for controllers that default the date. */
    public static function today(): string
    {
        return Clock::today()->toDateString();
    }
}
