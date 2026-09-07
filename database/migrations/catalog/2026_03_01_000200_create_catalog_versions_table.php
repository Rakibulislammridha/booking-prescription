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
        Schema::create('catalog_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('version', 32);
            $table->string('dgda_release_ref', 120)->nullable();
            $table->string('status', 12)->default('draft');
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->jsonb('row_counts')->default('{}');
            $table->char('checksum_sha256', 64)->nullable();
            $table->text('notes')->nullable();
            $table->string('applied_by', 120)->nullable();
            $table->timestampsTz();

            $table->unique(['version'], 'catalog_versions_version_uniq');
            $table->index(['status'], 'catalog_versions_status_idx');
            $table->index(['checksum_sha256'], 'catalog_versions_checksum_sha256_idx');
        });

        DB::statement("ALTER TABLE catalog_versions ADD CONSTRAINT catalog_versions_status_check CHECK (status IN ('draft', 'released', 'applied', 'superseded'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_versions');
    }
};
