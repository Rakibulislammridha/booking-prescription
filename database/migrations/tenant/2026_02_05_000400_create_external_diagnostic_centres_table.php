<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `external_diagnostic_centres`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_diagnostic_centres', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 200);
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('contact_person', 120)->nullable();
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_diagnostic_centres');
    }
};
