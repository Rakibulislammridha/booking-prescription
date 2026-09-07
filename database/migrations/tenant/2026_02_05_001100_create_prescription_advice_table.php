<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `prescription_advice` (immutable with the parent).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_advice', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
            $table->smallInteger('sort_order');
            $table->foreignId('advice_snippet_id')->nullable()->constrained('advice_snippets')->nullOnDelete();
            $table->text('text');
            $table->text('text_bn')->nullable();
            $table->timestampsTz();

            $table->unique(['prescription_id', 'sort_order'], 'prescription_advice_prescription_id_sort_order_uniq');
            $table->index(['advice_snippet_id'], 'prescription_advice_advice_snippet_id_idx');
        });

        DB::unprepared('CREATE TRIGGER prescription_children_immutable_trg BEFORE INSERT OR UPDATE OR DELETE ON prescription_advice FOR EACH ROW EXECUTE FUNCTION public.fn_prescription_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_advice');
    }
};
