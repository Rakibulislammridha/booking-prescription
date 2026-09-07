<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 80)->unique('departments_slug_uniq');
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['branch_id'], 'departments_branch_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
