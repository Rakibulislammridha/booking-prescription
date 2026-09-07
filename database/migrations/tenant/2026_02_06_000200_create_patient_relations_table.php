<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.2 `patient_relations`: a dependent points at the mobile-owning primary; at most one primary per dependent.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('primary_patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('dependent_patient_id')->unique('patient_relations_dependent_patient_id_uniq')->constrained('patients')->cascadeOnDelete();
            $table->string('relation', 16);
            $table->timestampsTz();

            $table->index(['primary_patient_id'], 'patient_relations_primary_patient_id_idx');
        });

        DB::statement("ALTER TABLE patient_relations ADD CONSTRAINT patient_relations_relation_check CHECK (relation IN ('spouse', 'child', 'parent', 'sibling', 'guardian_of', 'other'))");
        DB::statement('ALTER TABLE patient_relations ADD CONSTRAINT patient_relations_not_self_check CHECK (primary_patient_id <> dependent_patient_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_relations');
    }
};
