<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `prescriptions` — versioned, immutable once issued (§5.3). patient_id → patients(id) RESTRICT is owned
// by E's later migration (see visits). Triggers call the foundation's public.fn_prescription_guard() (§5.3.3):
//   - BEFORE UPDATE: only the post-issue column set may change on a non-draft row;
//   - BEFORE DELETE WHEN (OLD.status <> 'draft'): the same function raises for an issued/amended/voided row
//     (NEW is NULL there, so `NEW.snapshot IS DISTINCT FROM OLD.snapshot` is true); draft rows stay deletable.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('prescriptions_public_id_uniq');
            $table->foreignId('visit_id')->constrained('visits')->restrictOnDelete();
            $table->bigInteger('patient_id');
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('public.tenants')->restrictOnDelete();
            $table->smallInteger('version')->default(1);
            $table->bigInteger('root_prescription_id')->nullable();
            $table->bigInteger('supersedes_prescription_id')->nullable();
            $table->string('status', 10)->default('draft');
            $table->string('language', 5)->default('both');
            $table->timestampTz('issued_at')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('snapshot')->nullable();
            $table->char('snapshot_sha256', 64)->nullable();
            $table->jsonb('pad_snapshot')->nullable();
            $table->string('verification_code', 12)->nullable();
            $table->string('handwriting_image_path', 255)->nullable();
            $table->jsonb('drawing_json')->nullable();
            $table->string('drawing_image_path', 255)->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->timestampTz('pdf_generated_at')->nullable();
            $table->string('amend_reason', 255)->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->smallInteger('printed_count')->default(0);
            $table->timestampTz('last_printed_at')->nullable();
            $table->jsonb('delivered_channels')->default('[]');
            $table->timestampsTz();

            $table->foreign('root_prescription_id', 'prescriptions_root_prescription_id_foreign')->references('id')->on('prescriptions')->restrictOnDelete();
            $table->foreign('supersedes_prescription_id', 'prescriptions_supersedes_prescription_id_foreign')->references('id')->on('prescriptions')->restrictOnDelete();

            $table->index(['tenant_id'], 'prescriptions_tenant_id_idx');
            $table->index(['visit_id'], 'prescriptions_visit_id_idx');
            $table->index(['branch_id'], 'prescriptions_branch_id_idx');
            $table->index(['root_prescription_id'], 'prescriptions_root_prescription_id_idx');
            $table->index(['issued_by_user_id'], 'prescriptions_issued_by_user_id_idx');
            $table->index(['voided_by_user_id'], 'prescriptions_voided_by_user_id_idx');
        });

        DB::statement('CREATE INDEX prescriptions_patient_id_issued_at_idx ON prescriptions (patient_id, issued_at DESC)');
        DB::statement('CREATE INDEX prescriptions_doctor_id_issued_at_idx ON prescriptions (doctor_id, issued_at DESC)');
        DB::statement('CREATE UNIQUE INDEX prescriptions_verification_code_uniq_p ON prescriptions (verification_code) WHERE verification_code IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX prescriptions_root_version_uniq_p ON prescriptions (root_prescription_id, version) WHERE root_prescription_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX prescriptions_supersedes_uniq_p ON prescriptions (supersedes_prescription_id) WHERE supersedes_prescription_id IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX prescriptions_visit_draft_uniq_p ON prescriptions (visit_id) WHERE status = 'draft'");

        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_status_check CHECK (status IN ('draft', 'issued', 'amended', 'voided'))");
        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_language_check CHECK (language IN ('bn', 'en', 'both'))");
        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_issued_fields_check CHECK (status = 'draft' OR (issued_at IS NOT NULL AND snapshot IS NOT NULL AND verification_code IS NOT NULL))");
        DB::statement('ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_version_check CHECK (version >= 1)');
        DB::statement('ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_version_supersedes_check CHECK ((version = 1) = (supersedes_prescription_id IS NULL))');

        DB::unprepared('CREATE TRIGGER prescriptions_immutable_trg BEFORE UPDATE ON prescriptions FOR EACH ROW EXECUTE FUNCTION public.fn_prescription_guard()');
        DB::unprepared("CREATE TRIGGER prescriptions_no_delete_trg BEFORE DELETE ON prescriptions FOR EACH ROW WHEN (OLD.status <> 'draft') EXECUTE FUNCTION public.fn_prescription_guard()");
    }

    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
