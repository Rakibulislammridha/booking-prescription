<?php

declare(strict_types=1);

namespace App\Http\Requests\Site\Portal;

use App\Domain\Patients\Services\MobileNumber;
use Illuminate\Foundation\Http\FormRequest;

final class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string', 'max:20', fn (string $attr, mixed $value, \Closure $fail) => MobileNumber::isValid((string) $value) || $fail(__('patients.validation.mobile'))],
        ];
    }

    public function mobile(): string
    {
        return MobileNumber::normalize((string) $this->validated('mobile'));
    }
}
