<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allergy_classes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->jsonb('cross_reacts_with')->default('[]');
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['slug'], 'allergy_classes_slug_uniq');
            $table->index(['catalog_version_id'], 'allergy_classes_catalog_version_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allergy_classes');
    }
};
