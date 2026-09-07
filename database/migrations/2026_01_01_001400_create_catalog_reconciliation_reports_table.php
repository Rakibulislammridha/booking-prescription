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
        Schema::create('catalog_reconciliation_reports', function (Blueprint $table): void {
            $table->id();
            $table->char('run_id', 26);
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->bigInteger('catalog_version_id')->nullable();
            $table->string('table_name', 64);
            $table->string('column_name', 64);
            $table->integer('checked_count')->default(0);
            $table->integer('orphan_count')->default(0);
            $table->jsonb('sample_ids')->default('[]');
            $table->jsonb('details')->default('{}');
            $table->string('status', 16);
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by_super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestampTz('created_at')->nullable();

            $table->index(['run_id'], 'catalog_reconciliation_reports_run_id_idx');
            $table->index(['resolved_by_super_admin_id'], 'catalog_reconciliation_reports_resolved_by_super_admin_id_idx');
        });

        DB::statement('CREATE INDEX catalog_reconciliation_reports_tenant_id_created_at_idx ON catalog_reconciliation_reports (tenant_id, created_at DESC)');
        DB::statement('CREATE INDEX catalog_reconciliation_reports_status_idx_p ON catalog_reconciliation_reports (status) WHERE resolved_at IS NULL');
        DB::statement("ALTER TABLE catalog_reconciliation_reports ADD CONSTRAINT catalog_reconciliation_reports_status_check CHECK (status IN ('clean', 'orphans_found', 'inactive_found', 'renamed_found'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_reconciliation_reports');
    }
};
