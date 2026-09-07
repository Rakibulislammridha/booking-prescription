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
        Schema::create('custom_brand_promotions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('custom_brand_promotions_public_id_uniq');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->bigInteger('custom_brand_id');
            $table->string('brand_name', 160);
            $table->string('manufacturer', 160)->nullable();
            $table->bigInteger('generic_id');
            $table->string('generic_name', 160);
            $table->jsonb('snapshot');
            $table->string('status', 10)->default('pending');
            $table->timestampTz('submitted_at')->useCurrent();
            $table->foreignId('reviewed_by_super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->jsonb('decision')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'custom_brand_id'], 'custom_brand_promotions_tenant_id_custom_brand_id_uniq');
            $table->index(['status', 'submitted_at'], 'custom_brand_promotions_status_submitted_at_idx');
            $table->index(['reviewed_by_super_admin_id'], 'custom_brand_promotions_reviewed_by_super_admin_id_idx');
        });

        DB::statement('CREATE INDEX custom_brand_promotions_brand_name_trgm ON custom_brand_promotions USING gin (brand_name gin_trgm_ops)');
        DB::statement("ALTER TABLE custom_brand_promotions ADD CONSTRAINT custom_brand_promotions_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'promoted'))");
        DB::statement("ALTER TABLE custom_brand_promotions ADD CONSTRAINT custom_brand_promotions_reviewed_check CHECK ((status <> 'pending') = (reviewed_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_brand_promotions');
    }
};
