<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `public.catalog_jobs` — the super console's record of catalog maintenance it asked Horizon to do (CATALOG.md §5,
 * §4.4, §6): a bundle upload that becomes a dry run and then an applied import, a search-index rebuild, a
 * reconciliation sweep. `catalog_versions` (in the catalog database) remains the record of what was APPLIED; this
 * table is the record of what was ATTEMPTED, by whom, with what progress and what result — including dry runs, which
 * by design leave nothing in the catalog database at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_jobs', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique('catalog_jobs_public_id_uniq');
            $table->string('kind', 16);                         // import | reindex | reconcile
            $table->string('mode', 8)->nullable();              // import: dry_run | apply
            $table->string('status', 12)->default('uploaded');  // uploaded | queued | running | succeeded | failed
            $table->string('source', 8)->nullable();            // import: dgda | seed | manual
            $table->string('version', 32)->nullable();          // import: the catalog_versions.version label
            $table->string('release_ref', 120)->nullable();
            $table->boolean('full')->default(false);
            $table->text('bundle_path')->nullable();
            $table->jsonb('bundle_files')->default('[]');
            $table->char('checksum', 64)->nullable();
            $table->jsonb('progress')->default('{}');
            $table->jsonb('report')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('catalog_version_id')->nullable();   // soft ref → catalog.catalog_versions
            $table->foreignId('requested_by_super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['kind', 'created_at'], 'catalog_jobs_kind_created_at_idx');
            $table->index(['status'], 'catalog_jobs_status_idx');
        });

        DB::statement("ALTER TABLE catalog_jobs ADD CONSTRAINT catalog_jobs_kind_check CHECK (kind IN ('import', 'reindex', 'reconcile'))");
        DB::statement("ALTER TABLE catalog_jobs ADD CONSTRAINT catalog_jobs_status_check CHECK (status IN ('uploaded', 'queued', 'running', 'succeeded', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_jobs');
    }
};
