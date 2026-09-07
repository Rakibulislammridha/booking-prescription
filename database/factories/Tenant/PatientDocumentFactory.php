<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Patients\Enums\DocumentType;
use App\Domain\Patients\Enums\OcrStatus;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientDocument;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PatientDocument> */
final class PatientDocumentFactory extends Factory
{
    protected $model = PatientDocument::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $type = $this->faker->randomElement(DocumentType::cases());

        return [
            'patient_id' => Patient::factory(),
            'visit_id' => null,
            'type' => $type,
            'title' => ucfirst(str_replace('_', ' ', $type->value)).' – '.now()->format('d M Y'),
            'document_date' => $this->faker->boolean(60) ? $this->faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d') : null,
            'original_filename' => 'report-'.Str::lower(Str::random(6)).'.pdf',
            'storage_disk' => 'uploads',
            'storage_path' => 'tenants/0/patients/'.Str::ulid().'/'.Str::ulid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(10_000, 2_000_000),
            'ocr_status' => OcrStatus::Skipped,
            'ocr_text' => null,
            'uploaded_by_type' => User::class,
            'uploaded_by_id' => User::factory(),
        ];
    }

    public function image(): static
    {
        return $this->state(fn () => ['mime_type' => 'image/jpeg', 'original_filename' => 'scan.jpg']);
    }
}
