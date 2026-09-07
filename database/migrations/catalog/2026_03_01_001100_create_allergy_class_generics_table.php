<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allergy_class_generics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allergy_class_id')->constrained('allergy_classes')->cascadeOnDelete();
            $table->foreignId('generic_id')->constrained('generics')->cascadeOnDelete();
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->nullable();

            $table->unique(['allergy_class_id', 'generic_id'], 'allergy_class_generics_allergy_class_id_generic_id_uniq');
            $table->index(['generic_id'], 'allergy_class_generics_generic_id_idx');
            $table->index(['catalog_version_id'], 'allergy_class_generics_catalog_version_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allergy_class_generics');
    }
};
