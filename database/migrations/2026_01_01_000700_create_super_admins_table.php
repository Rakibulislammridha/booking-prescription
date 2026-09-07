<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('super_admins', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('email', 255)->unique('super_admins_email_uniq');
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password', 255);
            $table->text('two_factor_secret')->nullable();            // ENC
            $table->text('two_factor_recovery_codes')->nullable();    // ENC
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('super_admins');
    }
};
