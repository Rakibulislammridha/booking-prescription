<?php

declare(strict_types=1);

namespace App\Http\Requests\Site\Telemedicine;

use App\Domain\Booking\Data\BookingRequest;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Clinic\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /telemedicine/book. The same shape the public booking form posts, minus the channel: it is
 * `telemedicine` and nothing else, so no request can smuggle another channel through this route.
 */
final class StoreTelemedicineBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'session' => ['required', 'string', 'size:26'],
            'mobile' => ['required', 'string', 'max:20'],
            'otp' => ['nullable', 'string', 'max:8'],
            'name' => ['required', 'string', 'max:120'],
            'sex' => ['nullable', Rule::in(['m', 'f', 'o'])],
            'age_years' => ['nullable', 'integer', 'min:0', 'max:130'],
            'notes' => ['nullable', 'string', 'max:500'],
            'client_event_id' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'],
        ];
    }

    public function toData(bool $otpVerified): BookingRequest
    {
        $v = $this->validated();

        return new BookingRequest(
            channel: BookingChannel::Telemedicine,
            mobile: (string) $v['mobile'],
            name: (string) $v['name'],
            gender: match ($v['sex'] ?? null) {
                'm' => Gender::Male, 'f' => Gender::Female, 'o' => Gender::Other, default => null
            },
            ageYears: isset($v['age_years']) ? (int) $v['age_years'] : null,
            sessionPublicId: (string) $v['session'],
            clientEventId: strtoupper((string) $v['client_event_id']),
            notes: $v['notes'] ?? null,
            otpVerified: $otpVerified,
            isTelemedicine: true,
        );
    }
}
