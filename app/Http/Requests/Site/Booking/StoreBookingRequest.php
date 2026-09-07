<?php

declare(strict_types=1);

namespace App\Http\Requests\Site\Booking;

use App\Domain\Booking\Data\BookingRequest;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Clinic\Enums\Gender;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /booking — the public site (online) and the kiosk page (kiosk) share this shape; OTP verified by the controller. */
final class StoreBookingRequest extends FormRequest
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
            'channel' => ['sometimes', Rule::in(['online', 'kiosk'])],
            'mobile' => ['required', 'string', 'max:20'],
            'otp' => ['nullable', 'string', 'max:8'],
            'name' => ['required', 'string', 'max:120'],
            'sex' => ['nullable', Rule::in(['m', 'f', 'o'])],
            'age_years' => ['nullable', 'integer', 'min:0', 'max:130'],
            'notes' => ['nullable', 'string', 'max:500'],
            'client_event_id' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'],
            'slot_start_at' => ['nullable', 'date'],
            'kiosk_branch' => ['nullable', 'string', 'size:26'],
        ];
    }

    public function channel(): BookingChannel
    {
        return BookingChannel::from((string) $this->validated('channel', 'online'));
    }

    public function toData(bool $otpVerified): BookingRequest
    {
        $v = $this->validated();

        return new BookingRequest(
            channel: $this->channel(),
            mobile: (string) $v['mobile'],
            name: (string) $v['name'],
            gender: match ($v['sex'] ?? null) {
                'm' => Gender::Male, 'f' => Gender::Female, 'o' => Gender::Other, default => null
            },
            ageYears: isset($v['age_years']) ? (int) $v['age_years'] : null,
            sessionPublicId: (string) $v['session'],
            clientEventId: strtoupper((string) $v['client_event_id']),
            slotStartAt: isset($v['slot_start_at']) ? CarbonImmutable::parse((string) $v['slot_start_at'])->utc() : null,
            notes: $v['notes'] ?? null,
            otpVerified: $otpVerified,
        );
    }
}
