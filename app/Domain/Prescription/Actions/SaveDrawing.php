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

/** POST …/drawing {json, png} → drawing_json (jsonb) + drawing_image_path on the uploads disk (PRESCRIPTION.md §4.11). */
final class SaveDrawing
{
    public function __construct(private readonly HandwritingStorage $files, private readonly PrescriptionAuditor $auditor) {}

    /** @param  array<string, mixed>  $json  DrawingJson */
    public function handle(Prescription $rx, array $json, ?UploadedFile $png, Actor $actor): Prescription
    {
        if (! $rx->isDraft()) {
            throw new PrescriptionNotDraft($rx->id, $rx->status->value);
        }

        $attributes = ['drawing_json' => $json];

        if ($png !== null) {
            $path = $this->files->drawingPath($rx);
            Storage::disk($this->files->disk())->put($path, (string) $png->get());
            $attributes['drawing_image_path'] = $path;
        }

        $rx->forceFill($attributes)->save();
        $this->auditor->drawingSaved($rx);

        return $rx;
    }
}
