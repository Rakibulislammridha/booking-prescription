<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.5 `discounts` — every waiver is a row with a reason, an author and (above
// settings.billing.discount_approval_threshold_paisa) an approver. `value` is what the user typed;
// `amount_paisa` is the resolved integer that actually reduces the invoice.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('type', 12);
            $table->decimal('value', 10, 2);
            $table->bigInteger('amount_paisa');
            $table->string('reason_code', 24);
            $table->string('note', 255)->nullable();
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['invoice_id'], 'discounts_invoice_id_idx');
            $table->index(['applied_by_user_id'], 'discounts_applied_by_user_id_idx');
            $table->index(['approved_by_user_id'], 'discounts_approved_by_user_id_idx');
        });

        DB::statement("ALTER TABLE discounts ADD CONSTRAINT discounts_type_check CHECK (type IN ('percentage', 'fixed'))");
        DB::statement("ALTER TABLE discounts ADD CONSTRAINT discounts_reason_code_check CHECK (reason_code IN ('staff', 'poor_fund', 'followup', 'doctor_waiver', 'promo', 'other'))");
        DB::statement('ALTER TABLE discounts ADD CONSTRAINT discounts_amount_paisa_check CHECK (amount_paisa >= 0)');
        DB::statement('ALTER TABLE discounts ADD CONSTRAINT discounts_value_check CHECK (value >= 0)');
        DB::statement("ALTER TABLE discounts ADD CONSTRAINT discounts_percentage_value_check CHECK (type <> 'percentage' OR value <= 100)");
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
