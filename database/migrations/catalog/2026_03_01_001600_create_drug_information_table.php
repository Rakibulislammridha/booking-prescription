<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drug_information', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('generic_id')->constrained('generics')->cascadeOnDelete();
            $table->string('public_slug', 120);
            $table->text('indications')->nullable();
            $table->text('indications_bn')->nullable();
            $table->text('side_effects')->nullable();
            $table->text('side_effects_bn')->nullable();
            $table->text('contraindications')->nullable();
            $table->text('precautions')->nullable();
            $table->text('patient_advice_bn')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['generic_id'], 'drug_information_generic_id_uniq');
            $table->unique(['public_slug'], 'drug_information_public_slug_uniq');
            $table->index(['catalog_version_id'], 'drug_information_catalog_version_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drug_information');
    }
};
