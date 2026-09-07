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
        Schema::create('domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('domain', 253)->unique('domains_domain_uniq');
            $table->string('type', 16);
            $table->boolean('is_primary')->default(false);
            $table->string('verification_status', 16)->default('pending');
            $table->string('verification_token', 64);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->string('ssl_status', 16)->default('none');
            $table->timestampTz('ssl_expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id'], 'domains_tenant_id_idx');
            $table->index(['verification_status', 'last_checked_at'], 'domains_verification_status_last_checked_at_idx');
        });

        DB::statement('CREATE UNIQUE INDEX domains_tenant_id_uniq_p ON domains (tenant_id) WHERE is_primary');
        DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_type_check CHECK (type IN ('subdomain', 'custom'))");
        DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_verification_status_check CHECK (verification_status IN ('pending', 'verified', 'failed'))");
        DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_ssl_status_check CHECK (ssl_status IN ('none', 'pending', 'issued', 'failed'))");
        DB::statement('ALTER TABLE domains ADD CONSTRAINT domains_domain_check CHECK (domain = lower(domain))');
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
