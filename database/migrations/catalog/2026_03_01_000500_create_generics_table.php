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
        Schema::create('generics', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 160);
            $table->string('atc_code', 8)->nullable();
            $table->jsonb('aliases')->default('[]');
            $table->string('therapeutic_class', 120)->nullable();
            $table->boolean('is_controlled')->default(false);
            $table->boolean('is_pediatric_weight_based')->default(false);
            $table->string('name_bn', 200)->nullable();
            $table->jsonb('components')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['slug'], 'generics_slug_uniq');
            $table->index(['catalog_version_id'], 'generics_catalog_version_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX generics_lower_name_uniq ON generics (lower(name))');
        DB::statement('CREATE INDEX generics_name_trgm ON generics USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX generics_needs_review_idx_p ON generics (needs_review) WHERE needs_review');
        DB::statement("ALTER TABLE generics ADD CONSTRAINT generics_components_check CHECK (components IS NULL OR jsonb_typeof(components) = 'array')");
    }

    public function down(): void
    {
        Schema::dropIfExists('generics');
    }
};
