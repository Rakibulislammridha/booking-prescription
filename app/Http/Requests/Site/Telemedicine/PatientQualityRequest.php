<?php

declare(strict_types=1);

namespace App\Http\Requests\Site\Telemedicine;

use Illuminate\Foundation\Http\FormRequest;

/** Patient-reported WebRTC stats. Reachable from a patient's browser, so every value is bounded twice. */
final class PatientQualityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'avg_bitrate_kbps' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'packet_loss_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rtt_ms' => ['nullable', 'numeric', 'min:0', 'max:60000'],
        ];
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return array_filter($this->validated(), fn ($v) => $v !== null);
    }
}
