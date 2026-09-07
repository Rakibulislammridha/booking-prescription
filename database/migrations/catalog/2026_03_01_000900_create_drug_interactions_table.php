<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drug_interactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('generic_a_id')->constrained('generics')->restrictOnDelete();
            $table->foreignId('generic_b_id')->constrained('generics')->restrictOnDelete();
            $table->string('severity', 16);
            $table->text('mechanism')->nullable();
            $table->text('effect');
            $table->text('management')->nullable();
            $table->string('evidence_level', 16)->nullable();
            $table->string('source', 120)->nullable();
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['generic_a_id', 'generic_b_id'], 'drug_interactions_generic_a_id_generic_b_id_uniq');
            $table->index(['generic_b_id'], 'drug_interactions_generic_b_id_idx');
            $table->index(['catalog_version_id'], 'drug_interactions_catalog_version_id_idx');
        });

        DB::statement("ALTER TABLE drug_interactions ADD CONSTRAINT drug_interactions_severity_check CHECK (severity IN ('minor', 'moderate', 'major', 'contraindicated'))");
        DB::statement("ALTER TABLE drug_interactions ADD CONSTRAINT drug_interactions_evidence_level_check CHECK (evidence_level IS NULL OR evidence_level IN ('established', 'probable', 'theoretical'))");
        DB::statement('ALTER TABLE drug_interactions ADD CONSTRAINT drug_interactions_pair_order_check CHECK (generic_a_id < generic_b_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('drug_interactions');
    }
};
