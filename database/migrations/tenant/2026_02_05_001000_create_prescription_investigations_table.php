<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `prescription_investigations` (immutable with the parent).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_investigations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
            $table->smallInteger('sort_order');
            $table->foreignId('investigation_catalog_id')->nullable()->constrained('investigation_catalog')->nullOnDelete();
            $table->string('name', 200);
            $table->string('name_bn', 200)->nullable();
            $table->bigInteger('price_paisa')->nullable();
            $table->foreignId('external_diagnostic_centre_id')->nullable()->constrained('external_diagnostic_centres')->nullOnDelete();
            $table->string('referral_note', 500)->nullable();
            $table->boolean('is_urgent')->default(false);
            $table->timestampsTz();

            $table->unique(['prescription_id', 'sort_order'], 'prescription_investigations_prescription_id_sort_order_uniq');
            $table->index(['investigation_catalog_id'], 'prescription_investigations_investigation_catalog_id_idx');
            $table->index(['external_diagnostic_centre_id'], 'prescription_investigations_external_diagnostic_centre_id_idx');
        });

        DB::unprepared('CREATE TRIGGER prescription_children_immutable_trg BEFORE INSERT OR UPDATE OR DELETE ON prescription_investigations FOR EACH ROW EXECUTE FUNCTION public.fn_prescription_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_investigations');
    }
};
