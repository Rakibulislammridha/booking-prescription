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
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('generic_id')->constrained('generics')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug', 160);
            $table->string('manufacturer', 160)->nullable();
            $table->string('dar_number', 32)->nullable();
            $table->integer('popularity')->default(0);
            $table->jsonb('aliases')->default('[]');
            $table->timestampTz('discontinued_at')->nullable();
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['slug'], 'brands_slug_uniq');
            $table->index(['generic_id'], 'brands_generic_id_idx');
            $table->index(['catalog_version_id'], 'brands_catalog_version_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX brands_lower_name_generic_id_uniq ON brands (lower(name), generic_id)');
        DB::statement('CREATE INDEX brands_name_trgm ON brands USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
