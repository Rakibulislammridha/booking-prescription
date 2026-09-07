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
        Schema::create('audit_logs_central', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('action', 32);
            $table->string('auditable_type', 160)->nullable();
            $table->bigInteger('auditable_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->char('request_id', 26)->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id'], 'audit_logs_central_auditable_type_auditable_id_idx');
        });

        DB::statement('CREATE INDEX audit_logs_central_tenant_id_occurred_at_idx ON audit_logs_central (tenant_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_logs_central_super_admin_id_occurred_at_idx ON audit_logs_central (super_admin_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_logs_central_occurred_at_brin ON audit_logs_central USING brin (occurred_at)');
        DB::statement("ALTER TABLE audit_logs_central ADD CONSTRAINT audit_logs_central_action_check CHECK (action IN ('login', 'logout', 'impersonate', 'impersonate_end', 'create', 'update', 'delete', 'suspend', 'reactivate', 'plan_change', 'export', 'restore', 'catalog_promote', 'settings_change', 'view'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs_central');
    }
};
