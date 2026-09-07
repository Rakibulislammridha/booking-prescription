<?php

declare(strict_types=1);

namespace App\Http\Resources\Patients;

use App\Models\Tenant\PatientDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ocr_text (ENC) is never sent to the client list; the viewer fetches the file itself.
 *
 * @mixin PatientDocument
 */
final class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'visit_id' => $this->visit_id,
            'type' => $this->type->value,
            'title' => $this->title,
            'document_date' => $this->document_date?->toDateString(),
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'is_image' => $this->isImage(),
            'ocr_status' => $this->ocr_status->value,
            'uploaded_by_type' => class_basename($this->uploaded_by_type),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
