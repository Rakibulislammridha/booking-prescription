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
        Schema::create('pregnancy_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('generic_id')->constrained('generics')->cascadeOnDelete();
            $table->smallInteger('trimester')->nullable();
            $table->char('category', 1);
            $table->string('lactation', 8)->default('unknown');
            $table->text('notes')->nullable();
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['generic_id'], 'pregnancy_categories_generic_id_idx');
            $table->index(['catalog_version_id'], 'pregnancy_categories_catalog_version_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX pregnancy_categories_generic_id_trimester_uniq ON pregnancy_categories (generic_id, COALESCE(trimester, 0))');
        DB::statement("ALTER TABLE pregnancy_categories ADD CONSTRAINT pregnancy_categories_category_check CHECK (category IN ('A', 'B', 'C', 'D', 'X', 'N'))");
        DB::statement("ALTER TABLE pregnancy_categories ADD CONSTRAINT pregnancy_categories_lactation_check CHECK (lactation IN ('safe', 'caution', 'avoid', 'unknown'))");
        DB::statement('ALTER TABLE pregnancy_categories ADD CONSTRAINT pregnancy_categories_trimester_check CHECK (trimester IS NULL OR trimester BETWEEN 1 AND 3)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pregnancy_categories');
    }
};
