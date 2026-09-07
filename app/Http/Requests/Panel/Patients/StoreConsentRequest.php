<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Patients;

use App\Domain\Patients\Data\ConsentData;
use App\Domain\Patients\Enums\ConsentChannel;
use App\Domain\Patients\Enums\ConsentStatus;
use App\Domain\Patients\Enums\ConsentType;
use App\Models\Tenant\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $patient = $this->route('patient');

        return $patient instanceof Patient && ($this->user('web')?->can('recordConsent', $patient) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ConsentType::class)],
            'status' => ['required', Rule::enum(ConsentStatus::class)],
            'policy_version' => ['required', 'string', 'max:16'],
            'channel' => ['nullable', Rule::enum(ConsentChannel::class)],
            'signature_data' => ['nullable', 'string', 'max:200000', 'starts_with:data:image/png;base64,'],
            'otp_verified' => ['sometimes', 'boolean'],
            'text_shown' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function toData(): ConsentData
    {
        return ConsentData::fromRequest($this);
    }
}
