<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-added brands missing from the master catalog (SCHEMA §3.4 custom_brands). generic_id is NOT NULL: a brand
 * without a molecule is unrepresentable (BRIEF §3.4). Soft references to catalog are validated in code, never by FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_brands', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('generic_id');
            $table->string('generic_name', 160);
            $table->string('brand_name', 160);
            $table->string('manufacturer', 160)->nullable();
            $table->string('strength', 64)->nullable();
            $table->bigInteger('dosage_form_id')->nullable();
            $table->string('form', 48)->nullable();
            $table->bigInteger('route_id')->nullable();
            $table->string('route', 48)->nullable();
            $table->string('review_status', 10)->default('pending');
            $table->boolean('promoted_to_master')->default(false);
            $table->bigInteger('master_brand_id')->nullable();
            $table->bigInteger('master_strength_id')->nullable();
            $table->string('review_note', 255)->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('use_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['generic_id'], 'custom_brands_generic_id_idx');
            $table->index(['created_by_user_id'], 'custom_brands_created_by_user_id_idx');
        });

        DB::statement("CREATE UNIQUE INDEX custom_brands_identity_uniq ON custom_brands (lower(brand_name), COALESCE(strength, ''), COALESCE(form, '')) WHERE deleted_at IS NULL");
        DB::statement("CREATE INDEX custom_brands_review_status_idx_p ON custom_brands (review_status) WHERE review_status = 'pending'");
        DB::statement("ALTER TABLE custom_brands ADD CONSTRAINT custom_brands_review_status_check CHECK (review_status IN ('pending', 'approved', 'rejected', 'promoted'))");
        DB::statement("ALTER TABLE custom_brands ADD CONSTRAINT custom_brands_promoted_check CHECK (promoted_to_master = (review_status = 'promoted'))");
        DB::statement('ALTER TABLE custom_brands ADD CONSTRAINT custom_brands_master_brand_check CHECK (NOT promoted_to_master OR master_brand_id IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_brands');
    }
};
