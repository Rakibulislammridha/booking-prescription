<?php

declare(strict_types=1);

namespace App\Http\Resources\Prescription;

use App\Models\Tenant\Prescription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public /rx/{code} document (PRESCRIPTION.md §7.4): status banner (valid | superseded | voided), the frozen
 * snapshot (phone already masked at issue, no other identifiers), the version chain. Zero catalog queries (I6).
 *
 * @mixin Prescription
 */
final class VerificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $superseding = $this->supersededBy;
        $status = match (true) {
            $this->voided_at !== null => 'voided',
            $superseding !== null || $this->status->value === 'amended' => 'superseded',
            default => 'valid',
        };

        return [
            'status' => $status,
            'banner' => match ($status) {
                'voided' => ['key' => 'voided', 'voided_at' => $this->voided_at->toIso8601String()],
                'superseded' => ['key' => 'superseded', 'by_version' => $superseding?->version, 'by_date' => $superseding?->issued_at?->toIso8601String(), 'by_code' => $superseding?->verification_code],
                default => ['key' => 'valid'],
            },
            'version' => $this->version,
            'verification_code' => $this->verification_code,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'snapshot_sha256' => $this->snapshot_sha256,
            'snapshot' => $this->snapshot?->toArray() ?? [],
            'pdf_available' => $status === 'valid' && $this->pdf_path !== null,
            'purpose' => 'verify',
            'watermark' => $status === 'voided' ? 'VOID' : 'COPY',
        ];
    }
}
