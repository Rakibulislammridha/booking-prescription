<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Patients\Enums\DocumentType;
use App\Domain\Patients\Enums\OcrStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\PatientDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Uploaded report / document (SCHEMA §3.2). storage_path is a TenantPath key on `storage_disk`.
 *
 * @property int $id
 * @property int $patient_id
 * @property int|null $visit_id
 * @property DocumentType $type
 * @property string $title
 * @property CarbonImmutable|null $document_date
 * @property string $original_filename
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property OcrStatus $ocr_status
 * @property string|null $ocr_text
 * @property string $uploaded_by_type
 * @property int $uploaded_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Patient $patient
 * @property-read Model $uploadedBy
 */
final class PatientDocument extends TenantModel
{
    /** @use HasFactory<PatientDocumentFactory> */
    use HasFactory;

    protected static string $factory = PatientDocumentFactory::class;

    protected static bool $audited = true;

    protected $table = 'patient_documents';

    protected $fillable = [
        'patient_id', 'visit_id', 'type', 'title', 'document_date', 'original_filename', 'storage_disk', 'storage_path',
        'mime_type', 'size_bytes', 'ocr_status', 'ocr_text', 'uploaded_by_type', 'uploaded_by_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'document_date' => 'immutable_date',
            'size_bytes' => 'integer',
            'ocr_status' => OcrStatus::class,
            'ocr_text' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * User or Patient.
     *
     * @return MorphTo<Model, $this>
     */
    public function uploadedBy(): MorphTo
    {
        return $this->morphTo('uploadedBy', 'uploaded_by_type', 'uploaded_by_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
