<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Reception;

use App\Domain\Booking\Data\BookingRequest;
use App\Domain\Booking\Data\FeeOverride;
use App\Domain\Booking\Enums\AppointmentType;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Enums\FeeRule;
use App\Domain\Clinic\Enums\Gender;
use App\Domain\Clinic\Enums\Permission;
use App\Domain\Patients\Enums\PatientRelation;
use App\Domain\Serials\Enums\SerialPriority;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /panel/reception/bookings — the one-click counter booking dialog (phone / counter / walk-in / follow-up). */
final class StoreCounterBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', Appointment::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'session' => ['required', 'string', 'size:26'],
            'channel' => ['required', Rule::in(['counter', 'phone', 'walkin', 'followup'])],
            'patient' => ['nullable', 'string', 'size:26'],
            'mobile' => ['required_without:patient', 'nullable', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:120'],
            'sex' => ['nullable', Rule::in(['m', 'f', 'o', 'male', 'female', 'other'])],
            'age_years' => ['nullable', 'integer', 'min:0', 'max:130'],
            'dob' => ['nullable', 'date'],
            'relation' => ['nullable', Rule::enum(PatientRelation::class)],
            'priority' => ['sometimes', Rule::enum(SerialPriority::class)],
            'priority_reason' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::enum(AppointmentType::class)],
            'previous_appointment' => ['nullable', 'string', 'size:26'],
            'notes' => ['nullable', 'string', 'max:500'],
            'client_event_id' => ['nullable', 'string', 'size:26'],
            'slot_start_at' => ['nullable', 'date'],
            'fee_override_paisa' => ['nullable', 'integer', 'min:0'],
            'fee_override_rule' => ['nullable', Rule::in(['manual', 'waived'])],
            'fee_override_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toData(): BookingRequest
    {
        $v = $this->validated();
        /** @var User $user */
        $user = $this->user();
        $previous = isset($v['previous_appointment']) ? Appointment::query()->where('public_id', (string) $v['previous_appointment'])->first() : null;
        $override = null;

        if (isset($v['fee_override_paisa']) && $user->can(Permission::BillingFeesOverride->value)) {
            $override = new FeeOverride((int) $v['fee_override_paisa'], FeeRule::from((string) ($v['fee_override_rule'] ?? 'manual')), $v['fee_override_reason'] ?? null);
        }

        return new BookingRequest(
            channel: BookingChannel::from((string) $v['channel']),
            mobile: $v['mobile'] ?? null,
            patientPublicId: $v['patient'] ?? null,
            name: $v['name'] ?? null,
            gender: match ($v['sex'] ?? null) {
                'm', 'male' => Gender::Male, 'f', 'female' => Gender::Female, 'o', 'other' => Gender::Other, default => null
            },
            dob: isset($v['dob']) && $v['dob'] !== '' ? CarbonImmutable::parse((string) $v['dob'])->startOfDay() : null,
            ageYears: isset($v['age_years']) ? (int) $v['age_years'] : null,
            relation: isset($v['relation']) ? PatientRelation::from((string) $v['relation']) : PatientRelation::Other,
            sessionPublicId: (string) $v['session'],
            priority: SerialPriority::from((string) ($v['priority'] ?? 'normal')),
            priorityReason: $v['priority_reason'] ?? null,
            type: isset($v['type']) ? AppointmentType::from((string) $v['type']) : ($previous !== null ? AppointmentType::Followup : null),
            followUpOfAppointmentId: $previous?->id,
            clientEventId: isset($v['client_event_id']) ? strtoupper((string) $v['client_event_id']) : null,
            slotStartAt: isset($v['slot_start_at']) ? CarbonImmutable::parse((string) $v['slot_start_at'])->utc() : null,
            notes: $v['notes'] ?? null,
            feeOverride: $override,
            otpVerified: true,
        );
    }
}
