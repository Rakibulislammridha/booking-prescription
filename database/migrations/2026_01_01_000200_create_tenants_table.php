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
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('tenants_public_id_uniq');
            $table->string('name', 160);
            $table->string('slug', 80)->unique('tenants_slug_uniq');
            $table->string('schema_name', 63)->unique('tenants_schema_name_uniq');
            $table->string('status', 32)->default('trial');
            $table->string('timezone', 64)->default('Asia/Dhaka');
            $table->string('locale', 5)->default('bn');
            $table->char('currency', 3)->default('BDT');
            $table->string('owner_name', 160);
            $table->string('owner_email', 255);
            $table->string('owner_mobile', 20);
            $table->unsignedBigInteger('current_subscription_id')->nullable();   // FK added once subscriptions exists
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->string('suspension_reason', 255)->nullable();
            $table->jsonb('onboarding')->default('{}');
            $table->jsonb('branding')->default('{}');
            $table->timestampTz('provisioned_at')->nullable();
            $table->timestampTz('last_backup_at')->nullable();
            $table->timestampTz('data_export_requested_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status'], 'tenants_status_idx');
        });

        DB::statement("CREATE INDEX tenants_trial_ends_at_idx_p ON tenants (trial_ends_at) WHERE status = 'trial'");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('trial', 'active', 'past_due', 'suspended', 'cancelled'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_slug_check CHECK (slug ~ '^[a-z0-9][a-z0-9-]{1,78}$')");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_schema_name_check CHECK (schema_name ~ '^tenant_[a-z0-9_]{1,56}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
