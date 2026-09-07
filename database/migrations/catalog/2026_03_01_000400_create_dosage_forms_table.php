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
        Schema::create('dosage_forms', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 48);
            $table->string('name_bn', 64)->nullable();
            $table->string('abbreviation', 8);
            $table->string('default_unit', 16);
            $table->foreignId('default_route_id')->nullable()->constrained('routes')->nullOnDelete();
            $table->string('code', 16);
            $table->boolean('is_liquid')->default(false);
            $table->string('pack_unit', 16)->nullable();
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['code'], 'dosage_forms_code_uniq');
            $table->index(['default_route_id'], 'dosage_forms_default_route_id_idx');
            $table->index(['catalog_version_id'], 'dosage_forms_catalog_version_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX dosage_forms_lower_name_uniq ON dosage_forms (lower(name))');
        DB::statement("ALTER TABLE dosage_forms ADD CONSTRAINT dosage_forms_code_check CHECK (code IN ('tab', 'cap', 'syr', 'susp', 'sol', 'oral_drop', 'eye_drop', 'ear_drop', 'nasal_drop', 'nasal_spray', 'inh_mdi', 'inh_dpi', 'neb', 'inj', 'insulin', 'cream', 'oint', 'gel', 'lotion', 'powder', 'shampoo', 'mouthwash', 'paint', 'supp', 'pessary', 'sachet'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('dosage_forms');
    }
};
