<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.2 `patient_medications`. generic_id / brand_id are catalog soft references. The FKs to
// `prescription_items` (Prescription, 2026_02_05_*) and `custom_brands` (Catalog, 2026_02_06_5*) are owned by
// the module whose migration runs later; prescription_items is added here only when it already exists.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_medications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->bigInteger('generic_id')->nullable();
            $table->bigInteger('brand_id')->nullable();
            $table->bigInteger('custom_brand_id')->nullable();
            $table->string('generic_name', 160);
            $table->string('brand_name', 160)->nullable();
            $table->string('dose_text', 120)->nullable();
            $table->string('source', 16)->default('reported');
            $table->bigInteger('prescription_item_id')->nullable();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();                 // ENC
            $table->timestampsTz();

            $table->index(['patient_id'], 'patient_medications_patient_id_idx');
            $table->index(['custom_brand_id'], 'patient_medications_custom_brand_id_idx');
            $table->index(['prescription_item_id'], 'patient_medications_prescription_item_id_idx');
        });

        DB::statement('CREATE INDEX patient_medications_patient_id_idx_p ON patient_medications (patient_id) WHERE is_active');
        DB::statement("ALTER TABLE patient_medications ADD CONSTRAINT patient_medications_source_check CHECK (source IN ('prescription', 'reported'))");

        if (Schema::hasTable('prescription_items')) {
            Schema::table('patient_medications', function (Blueprint $table): void {
                $table->foreign('prescription_item_id')->references('id')->on('prescription_items')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_medications');
    }
};
