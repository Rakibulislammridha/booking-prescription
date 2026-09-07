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
        Schema::create('tenant_backups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('status', 16)->default('pending');
            $table->string('storage_disk', 32)->default('backups');
            $table->string('storage_path', 255)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('requested_by_super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['requested_by_super_admin_id'], 'tenant_backups_requested_by_super_admin_id_idx');
        });

        DB::statement('CREATE INDEX tenant_backups_tenant_id_completed_at_idx ON tenant_backups (tenant_id, completed_at DESC)');
        DB::statement("CREATE INDEX tenant_backups_expires_at_idx_p ON tenant_backups (expires_at) WHERE status = 'completed'");
        DB::statement("ALTER TABLE tenant_backups ADD CONSTRAINT tenant_backups_type_check CHECK (type IN ('daily', 'manual', 'export'))");
        DB::statement("ALTER TABLE tenant_backups ADD CONSTRAINT tenant_backups_status_check CHECK (status IN ('pending', 'running', 'completed', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_backups');
    }
};
