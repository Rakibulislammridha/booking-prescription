<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.5 `coupons`. `uses_count` is a cached counter; the authoritative count is coupon_redemptions
// (one row per invoice, UNIQUE invoice_id), so a lost increment can never let a coupon be redeemed twice on
// the same invoice.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique('coupons_code_uniq');
            $table->string('name', 120);
            $table->string('type', 12);
            $table->decimal('value', 10, 2);
            $table->bigInteger('max_discount_paisa')->nullable();
            $table->bigInteger('min_invoice_paisa')->default(0);
            $table->integer('max_uses')->nullable();
            $table->smallInteger('max_uses_per_patient')->default(1);
            $table->integer('uses_count')->default(0);
            $table->jsonb('applies_to')->default('{}');
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['created_by_user_id'], 'coupons_created_by_user_id_idx');
            $table->index(['is_active'], 'coupons_is_active_idx');
        });

        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_type_check CHECK (type IN ('percentage', 'fixed'))");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_code_check CHECK (code = upper(code))');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_value_check CHECK (value >= 0)');
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_percentage_value_check CHECK (type <> 'percentage' OR value <= 100)");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_max_discount_paisa_check CHECK (max_discount_paisa IS NULL OR max_discount_paisa >= 0)');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_min_invoice_paisa_check CHECK (min_invoice_paisa >= 0)');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_max_uses_check CHECK (max_uses IS NULL OR max_uses >= 0)');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_max_uses_per_patient_check CHECK (max_uses_per_patient >= 1)');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_uses_count_check CHECK (uses_count >= 0)');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_validity_check CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
