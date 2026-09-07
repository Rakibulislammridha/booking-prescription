<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Exceptions\PrescriptionNotDraft;
use App\Domain\Prescription\Services\HandwritingStorage;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Prescription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** POST …/handwriting {page, png} → uploads disk under TenantPath (PRESCRIPTION.md §4.12); page 1 fills handwriting_image_path. */
final class SaveHandwritingPage
{
    public function __construct(private readonly HandwritingStorage $files, private readonly PrescriptionAuditor $auditor) {}

    public function handle(Prescription $rx, int $page, UploadedFile $png, Actor $actor): string
    {
        if (! $rx->isDraft()) {
            throw new PrescriptionNotDraft($rx->id, $rx->status->value);
        }

        $path = $this->files->handwritingPath($rx, $page);
        Storage::disk($this->files->disk())->put($path, (string) $png->get());

        if ($page === 1 || $rx->handwriting_image_path === null) {
            $rx->forceFill(['handwriting_image_path' => $page === 1 ? $path : $rx->handwriting_image_path ?? $path])->save();
        }

        $this->auditor->handwritingUploaded($rx, $page);

        return $path;
    }
}
