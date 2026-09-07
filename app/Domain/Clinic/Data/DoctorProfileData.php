<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Data;

final readonly class DoctorProfileData
{
    /** @param  array<int, string>  $languages */
    public function __construct(
        public ?string $degrees = null,
        public ?string $degreesBn = null,
        public ?string $bmdcRegNo = null,
        public ?string $designation = null,
        public ?string $bio = null,
        public ?string $bioBn = null,
        public ?int $experienceYears = null,
        public array $languages = ['bn', 'en'],
        public int $newFeePaisa = 0,
        public int $followupFeePaisa = 0,
        public int $freeFollowupWithinDays = 0,
        public int $followupWithinDays = 30,
        public bool $reportVisitFree = true,
        public ?int $telemedicineFeePaisa = null,
        public int $onlineBookingFeeDeltaPaisa = 0,
        public bool $advancePaymentRequired = false,
        public ?string $chamberNotes = null,
    ) {}

    /** @param  array<string, mixed>  $v */
    public static function fromArray(array $v): self
    {
        return new self(
            degrees: $v['degrees'] ?? null,
            degreesBn: $v['degrees_bn'] ?? null,
            bmdcRegNo: $v['bmdc_reg_no'] ?? null,
            designation: $v['designation'] ?? null,
            bio: $v['bio'] ?? null,
            bioBn: $v['bio_bn'] ?? null,
            experienceYears: isset($v['experience_years']) ? (int) $v['experience_years'] : null,
            languages: $v['languages'] ?? ['bn', 'en'],
            newFeePaisa: (int) ($v['new_fee_paisa'] ?? 0),
            followupFeePaisa: (int) ($v['followup_fee_paisa'] ?? 0),
            freeFollowupWithinDays: (int) ($v['free_followup_within_days'] ?? 0),
            followupWithinDays: (int) ($v['followup_within_days'] ?? 30),
            reportVisitFree: (bool) ($v['report_visit_free'] ?? true),
            telemedicineFeePaisa: isset($v['telemedicine_fee_paisa']) ? (int) $v['telemedicine_fee_paisa'] : null,
            onlineBookingFeeDeltaPaisa: (int) ($v['online_booking_fee_delta_paisa'] ?? 0),
            advancePaymentRequired: (bool) ($v['advance_payment_required'] ?? false),
            chamberNotes: $v['chamber_notes'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'degrees' => $this->degrees, 'degrees_bn' => $this->degreesBn, 'bmdc_reg_no' => $this->bmdcRegNo, 'designation' => $this->designation,
            'bio' => $this->bio, 'bio_bn' => $this->bioBn, 'experience_years' => $this->experienceYears, 'languages' => $this->languages,
            'new_fee_paisa' => $this->newFeePaisa, 'followup_fee_paisa' => $this->followupFeePaisa,
            'free_followup_within_days' => $this->freeFollowupWithinDays, 'followup_within_days' => $this->followupWithinDays,
            'report_visit_free' => $this->reportVisitFree, 'telemedicine_fee_paisa' => $this->telemedicineFeePaisa,
            'online_booking_fee_delta_paisa' => $this->onlineBookingFeeDeltaPaisa, 'advance_payment_required' => $this->advancePaymentRequired,
            'chamber_notes' => $this->chamberNotes,
        ];
    }
}
