<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Patients\Enums\OcrStatus;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientDocument;
use App\Models\Tenant\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DocumentUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
        $this->asTenant('a');
    }

    public function test_upload_stores_under_the_tenant_path_names_the_document_and_audits(): void
    {
        $staff = $this->actingAsStaff(Role::Receptionist);
        $patient = Patient::factory()->create();

        $response = $this->postJson('/panel/patients/'.$patient->public_id.'/documents', [
            'file' => UploadedFile::fake()->create('cbc-report.pdf', 120, 'application/pdf'),
            'type' => 'lab_report',
        ])->assertCreated();

        $document = PatientDocument::query()->findOrFail($response->json('data.id'));
        $this->assertSame('uploads', $document->storage_disk);
        $this->assertMatchesRegularExpression('#^tenants/9001/patients/'.$patient->public_id.'/[0-9A-Z]{26}\.pdf$#', $document->storage_path);
        Storage::disk('uploads')->assertExists($document->storage_path);
        $this->assertSame('cbc-report.pdf', $document->original_filename);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame(120 * 1024, $document->size_bytes);
        $this->assertSame(User::class, $document->uploaded_by_type);
        $this->assertSame($staff->id, $document->uploaded_by_id);
        $this->assertSame(OcrStatus::Skipped, $document->ocr_status, 'NullDocumentNamer ran synchronously');
        $this->assertStringStartsWith(__('patients.documents.types.lab_report').' – ', $document->title);
        $this->assertSame($patient->id, $this->assertAudited(AuditAction::Create, $document)->patient_id);
        $response->assertJsonMissingPath('data.ocr_text')->assertJsonPath('data.is_image', false);
    }

    public function test_a_typed_title_survives_naming_and_images_are_accepted(): void
    {
        $this->actingAsDoctor();
        $patient = Patient::factory()->create();

        $this->post('/panel/patients/'.$patient->public_id.'/documents', [
            'file' => UploadedFile::fake()->image('xray.jpg', 800, 600),
            'type' => 'imaging',
            'title' => 'Chest X-ray PA view',
            'document_date' => '2026-08-30',
        ])->assertRedirect();

        $document = PatientDocument::query()->latest('id')->firstOrFail();
        $this->assertSame('Chest X-ray PA view', $document->title);
        $this->assertSame('2026-08-30', $document->document_date?->toDateString());
        $this->assertTrue($document->isImage());
        $this->assertSame(OcrStatus::Skipped, $document->ocr_status);
    }

    public function test_validation_rejects_other_types_oversized_files_and_missing_files(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $patient = Patient::factory()->create();
        $url = '/panel/patients/'.$patient->public_id.'/documents';

        $this->postJson($url, ['file' => UploadedFile::fake()->create('macro.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson($url, ['file' => UploadedFile::fake()->create('huge.pdf', 20_000, 'application/pdf')])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson($url, ['type' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson($url, ['file' => UploadedFile::fake()->create('ok.pdf', 10, 'application/pdf'), 'type' => 'selfie'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertSame(0, PatientDocument::query()->count());
    }

    public function test_accountants_cannot_upload_and_downloads_are_audited(): void
    {
        $patient = Patient::factory()->create();

        $this->actingAsStaff(Role::Accountant);
        $this->postJson('/panel/patients/'.$patient->public_id.'/documents', ['file' => UploadedFile::fake()->create('r.pdf', 10, 'application/pdf')])->assertForbidden();

        $this->actingAsStaff(Role::Receptionist);
        $upload = $this->postJson('/panel/patients/'.$patient->public_id.'/documents', ['file' => UploadedFile::fake()->create('r.pdf', 10, 'application/pdf')])->assertCreated();
        $document = PatientDocument::query()->findOrFail($upload->json('data.id'));

        $this->get('/panel/patients/'.$patient->public_id.'/documents/'.$document->id)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertSame($patient->id, $this->assertAudited(AuditAction::Download, $document)->patient_id);

        // A document of another patient is not reachable through this patient's URL (scoped binding).
        $stranger = Patient::factory()->create();
        $this->get('/panel/patients/'.$stranger->public_id.'/documents/'.$document->id)->assertNotFound();
    }
}
