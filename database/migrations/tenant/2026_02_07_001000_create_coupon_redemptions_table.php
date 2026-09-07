<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.5 `coupon_redemptions` — UNIQUE (invoice_id): one coupon per invoice, enforced by Postgres, so a
// double-submit of "apply coupon" cannot discount the same bill twice. created_at only (no updated_at).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->bigInteger('amount_paisa');
            $table->timestampTz('created_at')->nullable();

            $table->unique(['invoice_id'], 'coupon_redemptions_invoice_id_uniq');
            $table->index(['coupon_id', 'patient_id'], 'coupon_redemptions_coupon_id_patient_id_idx');
            $table->index(['patient_id'], 'coupon_redemptions_patient_id_idx');
        });

        DB::statement('ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemptions_amount_paisa_check CHECK (amount_paisa >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
