<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Patients;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Patients\Actions\UploadPatientDocument;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Patients\StoreDocumentRequest;
use App\Http\Resources\Patients\DocumentResource;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * POST /panel/patients/{patient}/documents (upload) and GET …/documents/{document} (inline view/download —
 * audited as `download`, ARCHITECTURE §8.1).
 */
final class PatientDocumentController extends Controller
{
    public function store(StoreDocumentRequest $request, Patient $patient, UploadPatientDocument $upload): JsonResponse|RedirectResponse
    {
        $document = $upload->handle($patient, $request->toData(), Actor::fromRequest($request));

        return $request->wantsJson()
            ? (new DocumentResource($document))->response()->setStatusCode(201)
            : back()->with('flash.success', __('patients.flash.document_uploaded', ['title' => $document->title]));
    }

    public function show(Patient $patient, PatientDocument $document, AuditRecorder $audit): StreamedResponse
    {
        $this->authorize('view', $patient);
        $audit->record(AuditAction::Download, $document, null, null, ['title' => $document->title]);

        $disposition = $document->isImage() || $document->mime_type === 'application/pdf' ? 'inline' : 'attachment';

        return Storage::disk($document->storage_disk)->response($document->storage_path, $document->original_filename, ['Content-Type' => $document->mime_type], $disposition);
    }
}
