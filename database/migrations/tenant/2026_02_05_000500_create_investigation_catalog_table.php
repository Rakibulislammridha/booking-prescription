<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `investigation_catalog` — the clinic's own priced test list (no lab workflow, BRIEF §6).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_catalog', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->string('code', 24)->nullable();
            $table->string('name', 200);
            $table->string('name_bn', 200)->nullable();
            $table->string('category', 16)->default('lab');
            $table->bigInteger('price_paisa')->default(0);
            $table->string('prep_instructions', 500)->nullable();
            $table->string('prep_instructions_bn', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['branch_id'], 'investigation_catalog_branch_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX investigation_catalog_branch_name_uniq ON investigation_catalog (COALESCE(branch_id, 0), lower(name))');
        DB::statement("ALTER TABLE investigation_catalog ADD CONSTRAINT investigation_catalog_category_check CHECK (category IN ('lab', 'imaging', 'procedure', 'other'))");
        DB::statement('ALTER TABLE investigation_catalog ADD CONSTRAINT investigation_catalog_price_paisa_check CHECK (price_paisa >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_catalog');
    }
};
