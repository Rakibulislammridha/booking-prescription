<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientAllergy;
use App\Models\Tenant\PatientConsent;
use App\Models\Tenant\PatientDocument;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ENC columns (SCHEMA §5.5): ciphertext at rest, plaintext through the model, never in the search document or audit rows. */
final class EncryptionAndSearchDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_patient_secret_columns_are_ciphertext_at_rest_and_plaintext_on_the_model(): void
    {
        $patient = Patient::factory()->create(['national_id' => '1234567890123', 'notes' => 'VIP — do not disclose']);
        $raw = DB::table('patients')->where('id', $patient->id)->first(['national_id', 'notes']);

        $this->assertNotNull($raw);
        $this->assertNotSame('1234567890123', $raw->national_id);
        $this->assertNotSame('VIP — do not disclose', $raw->notes);
        $this->assertStringNotContainsString('1234567890123', (string) $raw->national_id);
        $this->assertSame('1234567890123', $patient->fresh()->national_id);
        $this->assertSame('VIP — do not disclose', $patient->fresh()->notes);
    }

    public function test_child_record_secret_columns_are_encrypted_too(): void
    {
        $patient = Patient::factory()->create();
        $allergy = PatientAllergy::factory()->for($patient)->create(['notes' => 'anaphylaxis 2019']);
        $consent = PatientConsent::factory()->for($patient)->signed()->create();
        $document = PatientDocument::factory()->for($patient)->create(['ocr_text' => 'HbA1c 7.2%']);

        $this->assertNotSame('anaphylaxis 2019', DB::table('patient_allergies')->where('id', $allergy->id)->value('notes'));
        $this->assertStringStartsNotWith('data:image', (string) DB::table('patient_consents')->where('id', $consent->id)->value('signature_data'));
        $this->assertNotSame('HbA1c 7.2%', DB::table('patient_documents')->where('id', $document->id)->value('ocr_text'));
        $this->assertSame('HbA1c 7.2%', $document->fresh()->ocr_text);
    }

    public function test_the_search_document_carries_no_encrypted_field_and_no_address(): void
    {
        $patient = Patient::factory()->withEncryptedFields()->create(['address' => 'House 7, Road 2']);

        $doc = $patient->toSearchableArray();

        $this->assertSame(['id', 'public_id', 'name', 'mobile', 'mobile_local', 'patient_code', 'gender', 'registered_branch_id', 'is_active', 'age_text', 'last_visit_at'], array_keys($doc));
        $this->assertArrayNotHasKey('national_id', $doc);
        $this->assertArrayNotHasKey('notes', $doc);
        $this->assertArrayNotHasKey('address', $doc);
        $this->assertSame('01'.substr($patient->mobile, 5), $doc['mobile_local']);
        $this->assertSame(config('scout.prefix').'t9001_patients', $patient->searchableAs());
    }

    public function test_audit_rows_redact_encrypted_attributes(): void
    {
        $patient = Patient::factory()->create(['notes' => 'secret']);

        $this->assertSame(['notes' => '[encrypted]', 'name' => 'x'], AuditRecorder::redact($patient, ['notes' => 'secret', 'name' => 'x']));
        $created = $this->assertAudited(AuditAction::Create, $patient);
        $this->assertSame('[encrypted]', $created->after['notes'] ?? null);
        $this->assertArrayNotHasKey('national_id', array_filter($created->after ?? [], fn ($v) => $v !== '[encrypted]' && $v !== null));
    }
}
