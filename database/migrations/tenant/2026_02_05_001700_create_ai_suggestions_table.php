<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `ai_suggestions` — every AI-assist call and whether the doctor accepted it (created_at only).
// patient_id → patients(id) CASCADE is owned by E's later migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->bigInteger('patient_id');
            $table->string('type', 24);
            $table->string('provider', 32);
            $table->string('model', 64);
            $table->text('prompt');                                   // ENC
            $table->text('response');                                 // ENC
            $table->boolean('accepted')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->text('accepted_fragment')->nullable();            // ENC
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['visit_id'], 'ai_suggestions_visit_id_idx');
            $table->index(['patient_id'], 'ai_suggestions_patient_id_idx');
        });

        DB::statement('CREATE INDEX ai_suggestions_doctor_id_created_at_idx ON ai_suggestions (doctor_id, created_at DESC)');
        DB::statement("ALTER TABLE ai_suggestions ADD CONSTRAINT ai_suggestions_type_check CHECK (type IN ('history_summary', 'differential', 'advice', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_suggestions');
    }
};
