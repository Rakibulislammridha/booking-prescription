<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Support\Storage\TenantPath;
use Illuminate\Support\Facades\Storage;

/**
 * Object keys for handwriting / drawing files (PRESCRIPTION.md §4.11–§4.12):
 * tenants/{id}/patients/{patient_public_id}/prescriptions/{root_public_id}/v{n}/{handwriting-{page}|drawing}.png on the
 * `uploads` disk. Every key goes through TenantPath.
 */
final class HandwritingStorage
{
    public function disk(): string
    {
        return (string) config('prescription.uploads_disk', 'uploads');
    }

    public function basePath(Prescription $rx): string
    {
        $patientPublicId = $rx->relationLoaded('patient') ? $rx->patient->public_id : (string) Patient::query()->whereKey($rx->patient_id)->value('public_id');
        $rootPublicId = $rx->root_prescription_id !== null && $rx->root_prescription_id !== $rx->id
            ? (string) Prescription::query()->whereKey($rx->root_prescription_id)->value('public_id')
            : $rx->public_id;

        return TenantPath::for("patients/{$patientPublicId}/prescriptions/{$rootPublicId}/v{$rx->version}");
    }

    public function handwritingPath(Prescription $rx, int $page): string
    {
        return $this->basePath($rx)."/handwriting-{$page}.png";
    }

    public function drawingPath(Prescription $rx): string
    {
        return $this->basePath($rx).'/drawing.png';
    }

    /**
     * Every stored handwriting page path, page-ordered (the snapshot lists them).
     *
     * @return list<string>
     */
    public function handwritingPages(Prescription $rx): array
    {
        if ($rx->handwriting_image_path === null) {
            return [];
        }

        $files = Storage::disk($this->disk())->files(dirname($rx->handwriting_image_path));
        $pages = array_values(array_filter($files, fn ($f) => preg_match('~/handwriting-\d+\.png$~', $f) === 1));
        usort($pages, fn ($a, $b) => (int) preg_replace('/\D/', '', basename($a)) <=> (int) preg_replace('/\D/', '', basename($b)));

        return $pages;
    }

    /**
     * Copy handwriting/drawing files to the next version's folder (amend: copied, not moved).
     *
     * @return array{handwriting_image_path: string|null, drawing_image_path: string|null}
     */
    public function copyForVersion(Prescription $from, Prescription $to): array
    {
        $disk = Storage::disk($this->disk());
        $result = ['handwriting_image_path' => null, 'drawing_image_path' => null];

        foreach ($this->handwritingPages($from) as $path) {
            $target = $this->basePath($to).'/'.basename($path);

            if ($disk->exists($path)) {
                $disk->copy($path, $target);
                $result['handwriting_image_path'] ??= $target;
            }
        }

        if ($from->drawing_image_path !== null && $disk->exists($from->drawing_image_path)) {
            $target = $this->drawingPath($to);
            $disk->copy($from->drawing_image_path, $target);
            $result['drawing_image_path'] = $target;
        }

        return $result;
    }
}
