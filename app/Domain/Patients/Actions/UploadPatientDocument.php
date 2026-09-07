<?php

declare(strict_types=1);

namespace App\Domain\Patients\Actions;

use App\Domain\Patients\Data\DocumentUploadData;
use App\Domain\Patients\Enums\OcrStatus;
use App\Domain\Patients\Events\PatientDocumentUploaded;
use App\Domain\Patients\Jobs\NameUploadedDocument;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientDocument;
use App\Models\Tenant\User;
use App\Support\Storage\TenantPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores the file on the `uploads` disk under TenantPath (tenants/{id}/patients/{public_id}/{ulid}.{ext}),
 * writes the row (uploader = staff user or the patient) and queues NameUploadedDocument (OCR naming).
 */
final class UploadPatientDocument
{
    public function handle(Patient $patient, DocumentUploadData $data, Actor $actor): PatientDocument
    {
        $disk = (string) config('patients.documents.disk', 'uploads');
        $extension = strtolower($data->file->getClientOriginalExtension() ?: ($data->file->guessExtension() ?? 'bin'));
        $path = TenantPath::for("patients/{$patient->public_id}/".Str::ulid().'.'.$extension);

        $stored = Storage::disk($disk)->putFileAs(dirname($path), $data->file, basename($path));

        if ($stored === false) {
            throw new RuntimeException('Could not store the uploaded document.');
        }

        $document = DB::transaction(function () use ($patient, $data, $actor, $disk, $path): PatientDocument {
            [$byType, $byId] = $actor->userId !== null
                ? [User::class, $actor->userId]
                : [Patient::class, $actor->patientId ?? $patient->id];

            $document = PatientDocument::query()->create([
                'patient_id' => $patient->id,
                'visit_id' => $data->visitId,
                'type' => $data->type,
                'title' => $data->title ?? __('patients.documents.untitled'),
                'document_date' => $data->documentDate,
                'original_filename' => Str::limit($data->file->getClientOriginalName(), 250, ''),
                'storage_disk' => $disk,
                'storage_path' => $path,
                'mime_type' => (string) ($data->file->getMimeType() ?? $data->file->getClientMimeType()),
                'size_bytes' => max(1, (int) $data->file->getSize()),
                'ocr_status' => OcrStatus::Pending,
                'uploaded_by_type' => $byType,
                'uploaded_by_id' => $byId,
            ]);

            DB::afterCommit(function () use ($document, $data): void {
                NameUploadedDocument::dispatch($document->id, $data->title !== null);
                event(new PatientDocumentUploaded($document));
            });

            return $document;
        });

        return $document->refresh();
    }
}
