<?php

declare(strict_types=1);

namespace App\Domain\Booking\Data;

use App\Domain\Booking\Enums\AppointmentType;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Clinic\Enums\Gender;
use App\Domain\Patients\Enums\PatientRelation;
use App\Domain\Serials\Enums\SerialPriority;
use Carbon\CarbonImmutable;

/**
 * Input of BookAppointment — the LOCKED flow of BRIEF §5.C: mobile → patient auto-matched or created → doctor / date /
 * session → serial assigned atomically → fee snapshot → confirmation. Either `patientPublicId` (an already identified
 * person) or `mobile` (+ optional name/sex/age for a new household member) identifies the patient; either
 * `sessionPublicId` or (doctorSlug, date, sessionCode[, branchId]) identifies the session (materialised on demand).
 */
final readonly class BookingRequest
{
    public function __construct(
        public BookingChannel $channel,
        public ?string $mobile = null,
        public ?string $patientPublicId = null,
        public ?string $name = null,
        public ?Gender $gender = null,
        public ?CarbonImmutable $dob = null,
        public ?int $ageYears = null,
        public PatientRelation $relation = PatientRelation::Other,
        public ?string $sessionPublicId = null,
        public ?string $doctorSlug = null,
        public ?CarbonImmutable $date = null,
        public ?string $sessionCode = null,
        public ?int $branchId = null,
        public SerialPriority $priority = SerialPriority::Normal,
        public ?string $priorityReason = null,
        public ?AppointmentType $type = null,              // forced type (RebookFollowUp); null = FeeResolver decides
        public ?int $followUpOfAppointmentId = null,       // the previous appointment a follow-up refers to
        public ?string $clientEventId = null,              // ULID; idempotency for online/kiosk double submits
        public ?CarbonImmutable $slotStartAt = null,       // slot mode
        public ?string $notes = null,
        public ?FeeOverride $feeOverride = null,
        public bool $otpVerified = false,                  // set by the controller after OtpService::verify()
        public bool $isTelemedicine = false,
    ) {}

    /** @param  array<string, mixed>  $overrides */
    public function with(array $overrides): self
    {
        return new self(...array_merge(get_object_vars($this), $overrides));
    }
}
