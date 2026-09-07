<?php

declare(strict_types=1);

namespace App\Http\Requests\Site\Portal;

use App\Domain\Patients\Services\MobileNumber;
use Illuminate\Foundation\Http\FormRequest;

final class VerifyOtpRequest extends FormRequest
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
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    public function mobile(): string
    {
        return MobileNumber::normalize((string) $this->validated('mobile'));
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }
}
