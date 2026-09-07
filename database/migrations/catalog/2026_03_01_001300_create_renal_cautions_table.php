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
        Schema::create('renal_cautions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('generic_id')->constrained('generics')->cascadeOnDelete();
            $table->smallInteger('egfr_below')->nullable();
            $table->string('level', 12);
            $table->text('advice');
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['generic_id'], 'renal_cautions_generic_id_idx');
            $table->index(['catalog_version_id'], 'renal_cautions_catalog_version_id_idx');
        });

        // Importer canonical key (CATALOG.md §5.7: cautions upsert per generic + threshold).
        DB::statement('CREATE UNIQUE INDEX renal_cautions_generic_id_egfr_below_uniq ON renal_cautions (generic_id, COALESCE(egfr_below, -1))');
        DB::statement("ALTER TABLE renal_cautions ADD CONSTRAINT renal_cautions_level_check CHECK (level IN ('caution', 'adjust_dose', 'avoid'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('renal_cautions');
    }
};
