<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Patients;

use App\Domain\Patients\Data\DocumentUploadData;
use App\Domain\Patients\Enums\DocumentType;
use App\Models\Tenant\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/** Photo (jpg/png/webp) or PDF up to config('patients.documents.max_kb'). */
final class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $patient = $this->route('patient');

        return $patient instanceof Patient && ($this->user('web')?->can('uploadDocument', $patient) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'file' => ['required', File::types((array) config('patients.documents.mimes', ['jpg', 'jpeg', 'png', 'webp', 'pdf']))->max((int) config('patients.documents.max_kb', 10240))],
            'type' => ['nullable', Rule::enum(DocumentType::class)],
            'title' => ['nullable', 'string', 'max:200'],
            'document_date' => ['nullable', 'date', 'before_or_equal:today'],
            'visit_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function toData(): DocumentUploadData
    {
        return DocumentUploadData::fromRequest($this);
    }
}
