<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.2 `patients` + the per-schema `patient_code_seq` (SCHEMA §0.4). Identity = (mobile, name_normalized,
// COALESCE(dob)) among live rows (§5.4); mobile stays plain and non-unique (a household shares one number).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS patient_code_seq START WITH 1 INCREMENT BY 1');

        Schema::create('patients', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('patients_public_id_uniq');
            $table->foreignId('tenant_id')->constrained('public.tenants')->restrictOnDelete();   // isolation assertion (SCHEMA §5.9)
            $table->string('patient_code', 16)->unique('patients_patient_code_uniq');
            $table->string('name', 160);
            $table->string('name_normalized', 160)->storedAs('lower(btrim(name))');
            $table->string('mobile', 20);
            $table->boolean('is_mobile_owner')->default(true);
            $table->string('gender', 8)->nullable();
            $table->date('dob')->nullable();
            $table->boolean('dob_is_estimated')->default(false);
            $table->string('blood_group', 3)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('address')->nullable();
            $table->string('district', 64)->nullable();
            $table->text('national_id')->nullable();           // ENC
            $table->string('guardian_name', 160)->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->string('preferred_language', 5)->default('bn');
            $table->text('notes')->nullable();                 // ENC
            $table->jsonb('tags')->default('[]');
            $table->foreignId('registered_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('registered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 16)->default('counter');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_visit_at')->nullable();
            $table->integer('visit_count')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id'], 'patients_tenant_id_idx');
            $table->index(['mobile'], 'patients_mobile_idx');
            $table->index(['registered_branch_id'], 'patients_registered_branch_id_idx');
            $table->index(['registered_by_user_id'], 'patients_registered_by_user_id_idx');
        });

        DB::statement("CREATE UNIQUE INDEX patients_identity_uniq ON patients (mobile, name_normalized, COALESCE(dob, DATE '0001-01-01')) WHERE deleted_at IS NULL");
        DB::statement('CREATE INDEX patients_name_normalized_trgm ON patients USING gin (name_normalized public.gin_trgm_ops)');
        DB::statement('CREATE INDEX patients_last_visit_at_idx ON patients (last_visit_at DESC)');

        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_gender_check CHECK (gender IS NULL OR gender IN ('male', 'female', 'other'))");
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_blood_group_check CHECK (blood_group IS NULL OR blood_group IN ('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'))");
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_preferred_language_check CHECK (preferred_language IN ('bn', 'en'))");
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_source_check CHECK (source IN ('online', 'counter', 'kiosk', 'import', 'walkin'))");
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_mobile_check CHECK (mobile ~ '^\\+8801[3-9]\\d{8}$')");
        DB::statement('ALTER TABLE patients ADD CONSTRAINT patients_dob_check CHECK (dob IS NULL OR dob <= CURRENT_DATE)');
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
        DB::statement('DROP SEQUENCE IF EXISTS patient_code_seq');
    }
};
