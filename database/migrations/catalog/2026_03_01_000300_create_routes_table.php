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
        Schema::create('routes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 48);
            $table->string('name_bn', 64)->nullable();
            $table->string('abbreviation', 8);
            $table->string('code', 8);
            $table->boolean('is_systemic')->default(true);
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['code'], 'routes_code_uniq');
            $table->index(['catalog_version_id'], 'routes_catalog_version_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX routes_lower_name_uniq ON routes (lower(name))');
        DB::statement("ALTER TABLE routes ADD CONSTRAINT routes_code_check CHECK (code IN ('po', 'sl', 'buccal', 'pr', 'pv', 'top', 'iv', 'im', 'sc', 'id', 'inh', 'neb', 'ng', 'le', 're', 'be', 'lear', 'rear', 'bear', 'nasal'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
