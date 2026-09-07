<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.5 `invoice_items`. `line_total_paisa = quantity * unit_price_paisa` is a CHECK, so a line total can
// never disagree with its parts — discounts are invoice-level rows, never a silent line rewrite. The doctor's
// commission is FROZEN here (doctor_revenue_share_id + the two share columns) at issue time.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->smallInteger('sort_order');
            $table->string('type', 16);
            $table->string('description', 200);
            $table->string('reference_type', 160)->nullable();
            $table->bigInteger('reference_id')->nullable();
            $table->foreignId('doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->smallInteger('quantity')->default(1);
            $table->bigInteger('unit_price_paisa');
            $table->bigInteger('line_total_paisa');
            $table->foreignId('doctor_revenue_share_id')->nullable()->constrained('doctor_revenue_shares')->nullOnDelete();
            $table->bigInteger('doctor_share_paisa')->default(0);
            $table->bigInteger('clinic_share_paisa')->default(0);
            $table->timestampsTz();

            $table->unique(['invoice_id', 'sort_order'], 'invoice_items_invoice_id_sort_order_uniq');
            $table->index(['doctor_id'], 'invoice_items_doctor_id_idx');
            $table->index(['doctor_revenue_share_id'], 'invoice_items_doctor_revenue_share_id_idx');
            $table->index(['reference_type', 'reference_id'], 'invoice_items_reference_type_reference_id_idx');
        });

        DB::statement("ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_type_check CHECK (type IN ('consultation', 'followup', 'investigation', 'telemedicine', 'other'))");
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_unit_price_paisa_check CHECK (unit_price_paisa >= 0)');
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_line_total_paisa_check CHECK (line_total_paisa = quantity * unit_price_paisa)');
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_share_paisa_check CHECK (doctor_share_paisa >= 0 AND clinic_share_paisa >= 0 AND doctor_share_paisa + clinic_share_paisa <= line_total_paisa)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
