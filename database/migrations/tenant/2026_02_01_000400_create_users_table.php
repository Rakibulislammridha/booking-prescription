<?php

declare(strict_types=1);

use App\Tenancy\Facades\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('users_public_id_uniq');
            $table->foreignId('tenant_id')->constrained('public.tenants')->restrictOnDelete();   // isolation assertion (SCHEMA §5.9)
            $table->string('name', 160);
            $table->string('email', 255)->unique('users_email_uniq');
            $table->string('mobile', 20)->nullable();
            $table->string('password', 255);
            $table->timestampTz('email_verified_at')->nullable();
            $table->rememberToken();
            $table->foreignId('default_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('locale', 5)->default('bn');
            $table->string('avatar_path', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->text('two_factor_secret')->nullable();            // ENC
            $table->text('two_factor_recovery_codes')->nullable();    // ENC
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            $table->smallInteger('session_timeout_minutes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id'], 'users_tenant_id_idx');
            $table->index(['default_branch_id'], 'users_default_branch_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX users_mobile_uniq_p ON users (mobile) WHERE mobile IS NOT NULL');

        // Per-schema isolation assertion (SCHEMA §5.9): this schema belongs to exactly one tenant, whose id is known
        // here because tenant migrations run inside Tenancy::run(). No write path can move a row to another tenant.
        DB::statement(sprintf('ALTER TABLE users ADD CONSTRAINT users_tenant_id_check CHECK (tenant_id = %d)', Tenancy::id() ?? throw new RuntimeException('users migration must run inside Tenancy::run()')));
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
