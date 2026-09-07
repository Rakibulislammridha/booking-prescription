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
        Schema::create('hepatic_cautions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('generic_id')->constrained('generics')->cascadeOnDelete();
            $table->char('child_pugh_class', 1)->nullable();
            $table->string('level', 12);
            $table->text('advice');
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['generic_id'], 'hepatic_cautions_generic_id_idx');
            $table->index(['catalog_version_id'], 'hepatic_cautions_catalog_version_id_idx');
        });

        DB::statement("CREATE UNIQUE INDEX hepatic_cautions_generic_id_child_pugh_class_uniq ON hepatic_cautions (generic_id, COALESCE(child_pugh_class, '-'))");
        DB::statement("ALTER TABLE hepatic_cautions ADD CONSTRAINT hepatic_cautions_level_check CHECK (level IN ('caution', 'adjust_dose', 'avoid'))");
        DB::statement("ALTER TABLE hepatic_cautions ADD CONSTRAINT hepatic_cautions_child_pugh_class_check CHECK (child_pugh_class IS NULL OR child_pugh_class IN ('A', 'B', 'C'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('hepatic_cautions');
    }
};
