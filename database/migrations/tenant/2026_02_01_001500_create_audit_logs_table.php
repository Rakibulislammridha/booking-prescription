<?php

declare(strict_types=1);

use App\Tenancy\Facades\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Append-only (SCHEMA §3.7). patient_id is a plain indexed bigint here: `patients` is created by the Patients module
// (2026_02_06_*), which adds the FK (ON DELETE SET NULL) in its own migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('public.tenants')->restrictOnDelete();
            $table->string('actor_type', 12);
            $table->bigInteger('actor_id')->nullable();
            $table->bigInteger('impersonator_super_admin_id')->nullable();
            $table->string('action', 16);
            $table->string('auditable_type', 160);
            $table->bigInteger('auditable_id');
            $table->bigInteger('patient_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('context')->default('{}');
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->char('request_id', 26)->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index(['tenant_id'], 'audit_logs_tenant_id_idx');
        });

        DB::statement('CREATE INDEX audit_logs_auditable_type_auditable_id_occurred_at_idx ON audit_logs (auditable_type, auditable_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_logs_patient_id_occurred_at_idx ON audit_logs (patient_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_logs_actor_type_actor_id_occurred_at_idx ON audit_logs (actor_type, actor_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_logs_occurred_at_brin ON audit_logs USING brin (occurred_at)');
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check CHECK (actor_type IN ('user', 'patient', 'device', 'system', 'super_admin'))");
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_action_check CHECK (action IN ('view', 'create', 'update', 'delete', 'print', 'export', 'reorder', 'issue', 'amend', 'void', 'check_in', 'transfer', 'login', 'logout', 'download', 'share', 'refund'))");
        // Per-schema isolation assertion (SCHEMA §5.9): the tenant id is known because tenant migrations run inside Tenancy::run().
        DB::statement(sprintf('ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_tenant_id_check CHECK (tenant_id = %d)', Tenancy::id() ?? throw new RuntimeException('audit_logs migration must run inside Tenancy::run()')));
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
