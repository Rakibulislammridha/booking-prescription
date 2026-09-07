<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.5 `doctor_revenue_shares` — commission RULES only. The applicable rule is snapshotted onto
// invoice_items (doctor_revenue_share_id + doctor_share_paisa + clinic_share_paisa) when the invoice is issued,
// so changing a rule never rewrites history. Resolution: most specific (branch set, exact item_type) wins,
// newest effective_from on ties.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_revenue_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->string('item_type', 16)->default('all');
            $table->string('share_type', 12);
            $table->decimal('share_value', 10, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['doctor_id', 'item_type', 'effective_from'], 'doctor_revenue_shares_doctor_id_item_type_effective_from_idx');
            $table->index(['branch_id'], 'doctor_revenue_shares_branch_id_idx');
            $table->index(['created_by_user_id'], 'doctor_revenue_shares_created_by_user_id_idx');
        });

        DB::statement("ALTER TABLE doctor_revenue_shares ADD CONSTRAINT doctor_revenue_shares_item_type_check CHECK (item_type IN ('consultation', 'followup', 'investigation', 'telemedicine', 'all'))");
        DB::statement("ALTER TABLE doctor_revenue_shares ADD CONSTRAINT doctor_revenue_shares_share_type_check CHECK (share_type IN ('percentage', 'fixed'))");
        DB::statement("ALTER TABLE doctor_revenue_shares ADD CONSTRAINT doctor_revenue_shares_share_value_check CHECK (share_type <> 'percentage' OR share_value BETWEEN 0 AND 100)");
        DB::statement('ALTER TABLE doctor_revenue_shares ADD CONSTRAINT doctor_revenue_shares_share_value_sign_check CHECK (share_value >= 0)');
        DB::statement('ALTER TABLE doctor_revenue_shares ADD CONSTRAINT doctor_revenue_shares_effective_to_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_revenue_shares');
    }
};
