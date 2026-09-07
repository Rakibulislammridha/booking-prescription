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
        Schema::create('impersonation_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('super_admin_id')->constrained('super_admins')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->bigInteger('user_id');                         // users.id inside the tenant schema — soft reference
            $table->char('token_hash', 64)->unique('impersonation_tokens_token_hash_uniq');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['super_admin_id'], 'impersonation_tokens_super_admin_id_idx');
            $table->index(['tenant_id'], 'impersonation_tokens_tenant_id_idx');
        });

        DB::statement('CREATE INDEX impersonation_tokens_expires_at_idx_p ON impersonation_tokens (expires_at) WHERE consumed_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_tokens');
    }
};
