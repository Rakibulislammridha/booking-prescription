<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Support\Storage\TenantPath;
use Illuminate\Support\Facades\Storage;

/**
 * Object keys for rendered PDFs (PRESCRIPTION.md §7.5, ARCHITECTURE §8.7):
 * tenants/{id}/patients/{patient_public_id}/prescriptions/{root_public_id}/v{n}/{code}.pdf on the `pdfs` disk.
 * Keyed by version, so an amended chain keeps every version's file side by side and v1 stays downloadable forever.
 */
final class PdfStorage
{
    public function disk(): string
    {
        return (string) config('prescription.pdfs_disk', 'pdfs');
    }

    public function path(Prescription $rx): string
    {
        $patientPublicId = $rx->relationLoaded('patient') ? $rx->patient->public_id : (string) Patient::query()->whereKey($rx->patient_id)->value('public_id');
        $rootPublicId = $rx->root_prescription_id !== null && $rx->root_prescription_id !== $rx->id
            ? (string) Prescription::query()->whereKey($rx->root_prescription_id)->value('public_id')
            : $rx->public_id;
        $code = $rx->verification_code ?? $rx->public_id;

        return TenantPath::for("patients/{$patientPublicId}/prescriptions/{$rootPublicId}/v{$rx->version}/{$code}.pdf");
    }

    public function exists(Prescription $rx): bool
    {
        return $rx->pdf_path !== null && Storage::disk($this->disk())->exists($rx->pdf_path);
    }

    public function put(Prescription $rx, string $bytes): string
    {
        $path = $this->path($rx);
        Storage::disk($this->disk())->put($path, $bytes);

        return $path;
    }
}
