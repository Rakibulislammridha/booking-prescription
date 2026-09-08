<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Telemedicine;

use Illuminate\Foundation\Http\FormRequest;

/** Client-reported WebRTC stats (SCHEMA §3.8 `quality`). Values are clamped again in the action. */
final class QualityReportRequest extends FormRequest
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
