<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Booking;

use App\Domain\Booking\Data\BookingRequest;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Clinic\Enums\Gender;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/public/bookings (SERIAL_ENGINE §16): {doctor_slug, date, session_code | session, mobile, otp, patient{…}, client_event_id}. */
final class StorePublicBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'session' => ['required_without:doctor_slug', 'nullable', 'string', 'size:26'],
            'doctor_slug' => ['required_without:session', 'nullable', 'string', 'max:80'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'session_code' => ['nullable', 'string', 'max:2'],
            'branch' => ['nullable', 'string', 'max:80'],
            'mobile' => ['required', 'string', 'max:20'],
            'otp' => ['nullable', 'string', 'max:8'],
            'patient' => ['required', 'array'],
            'patient.name' => ['required', 'string', 'max:120'],
            'patient.sex' => ['nullable', Rule::in(['m', 'f', 'o'])],
            'patient.age_years' => ['nullable', 'integer', 'min:0', 'max:130'],
            'slot_start_at' => ['nullable', 'date'],
            'client_event_id' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function toData(bool $otpVerified, ?int $branchId): BookingRequest
    {
        $v = $this->validated();
        $patient = (array) $v['patient'];

        return new BookingRequest(
            channel: BookingChannel::Online,
            mobile: (string) $v['mobile'],
            name: (string) $patient['name'],
            gender: match ($patient['sex'] ?? null) {
                'm' => Gender::Male, 'f' => Gender::Female, 'o' => Gender::Other, default => null
            },
            ageYears: isset($patient['age_years']) ? (int) $patient['age_years'] : null,
            sessionPublicId: $v['session'] ?? null,
            doctorSlug: $v['doctor_slug'] ?? null,
            date: isset($v['date']) ? CarbonImmutable::parse((string) $v['date'], Clock::timezone())->startOfDay() : Clock::today(),
            sessionCode: $v['session_code'] ?? 'A',
            branchId: $branchId,
            clientEventId: strtoupper((string) $v['client_event_id']),
            slotStartAt: isset($v['slot_start_at']) ? CarbonImmutable::parse((string) $v['slot_start_at'])->utc() : null,
            notes: $v['notes'] ?? null,
            otpVerified: $otpVerified,
        );
    }
}
