<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.2 `patient_documents`. storage_path is a TenantPath object key. The FK to `visits`
// (Prescription, 2026_02_05_*) is added only when that table already exists.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->bigInteger('visit_id')->nullable();
            $table->string('type', 24)->default('other');
            $table->string('title', 200);
            $table->date('document_date')->nullable();
            $table->string('original_filename', 255);
            $table->string('storage_disk', 32)->default('s3');
            $table->string('storage_path', 255);
            $table->string('mime_type', 96);
            $table->bigInteger('size_bytes');
            $table->string('ocr_status', 16)->default('pending');
            $table->text('ocr_text')->nullable();              // ENC
            $table->string('uploaded_by_type', 160);
            $table->bigInteger('uploaded_by_id');
            $table->timestampsTz();

            $table->index(['visit_id'], 'patient_documents_visit_id_idx');
            $table->index(['uploaded_by_type', 'uploaded_by_id'], 'patient_documents_uploaded_by_type_uploaded_by_id_idx');
        });

        DB::statement('CREATE INDEX patient_documents_patient_id_document_date_idx ON patient_documents (patient_id, document_date DESC)');
        DB::statement("ALTER TABLE patient_documents ADD CONSTRAINT patient_documents_type_check CHECK (type IN ('lab_report', 'imaging', 'external_prescription', 'discharge_summary', 'identity', 'other'))");
        DB::statement("ALTER TABLE patient_documents ADD CONSTRAINT patient_documents_ocr_status_check CHECK (ocr_status IN ('pending', 'done', 'failed', 'skipped'))");
        DB::statement('ALTER TABLE patient_documents ADD CONSTRAINT patient_documents_size_bytes_check CHECK (size_bytes > 0)');

        if (Schema::hasTable('visits')) {
            Schema::table('patient_documents', function (Blueprint $table): void {
                $table->foreign('visit_id')->references('id')->on('visits')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_documents');
    }
};
