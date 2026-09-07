<?php

declare(strict_types=1);

namespace App\Http\Requests\Site\Booking;

use Illuminate\Foundation\Http\FormRequest;

final class RequestBookingOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['mobile' => ['required', 'string', 'max:20']];
    }
}
