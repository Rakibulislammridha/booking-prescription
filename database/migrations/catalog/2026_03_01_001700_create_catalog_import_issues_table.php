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
        Schema::create('catalog_import_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_version_id')->constrained('catalog_versions')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->integer('source_row')->nullable();
            $table->jsonb('payload');
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolved_by', 120)->nullable();
            $table->jsonb('resolution')->nullable();
            $table->timestampsTz();

            $table->index(['catalog_version_id', 'kind'], 'catalog_import_issues_catalog_version_id_kind_idx');
        });

        DB::statement('CREATE INDEX catalog_import_issues_kind_idx_p ON catalog_import_issues (kind) WHERE resolved_at IS NULL');
        DB::statement("ALTER TABLE catalog_import_issues ADD CONSTRAINT catalog_import_issues_kind_check CHECK (kind IN ('unknown_generic', 'unparsable_strength', 'unknown_form', 'duplicate_brand'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_issues');
    }
};
