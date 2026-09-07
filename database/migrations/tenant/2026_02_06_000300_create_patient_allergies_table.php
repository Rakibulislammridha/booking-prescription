<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.2 `patient_allergies`. generic_id / allergy_class_id are soft references to the catalog database.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_allergies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('allergen_type', 16);
            $table->bigInteger('generic_id')->nullable();
            $table->bigInteger('allergy_class_id')->nullable();
            $table->string('allergen_name', 160);
            $table->string('reaction', 255)->nullable();
            $table->string('severity', 16)->default('unknown');
            $table->text('notes')->nullable();                 // ENC
            $table->boolean('is_active')->default(true);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['patient_id'], 'patient_allergies_patient_id_idx');
            $table->index(['recorded_by_user_id'], 'patient_allergies_recorded_by_user_id_idx');
            $table->index(['verified_by_doctor_id'], 'patient_allergies_verified_by_doctor_id_idx');
        });

        DB::statement('CREATE INDEX patient_allergies_patient_id_idx_p ON patient_allergies (patient_id) WHERE is_active');
        DB::statement("ALTER TABLE patient_allergies ADD CONSTRAINT patient_allergies_allergen_type_check CHECK (allergen_type IN ('generic', 'allergy_class', 'food', 'environmental', 'other'))");
        DB::statement("ALTER TABLE patient_allergies ADD CONSTRAINT patient_allergies_severity_check CHECK (severity IN ('mild', 'moderate', 'severe', 'unknown'))");
        DB::statement("ALTER TABLE patient_allergies ADD CONSTRAINT patient_allergies_generic_check CHECK ((allergen_type = 'generic') = (generic_id IS NOT NULL))");
        DB::statement("ALTER TABLE patient_allergies ADD CONSTRAINT patient_allergies_allergy_class_check CHECK ((allergen_type = 'allergy_class') = (allergy_class_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_allergies');
    }
};
