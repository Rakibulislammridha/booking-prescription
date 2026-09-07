<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialties', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('name_bn', 160)->nullable();
            $table->string('slug', 80)->unique('specialties_slug_uniq');
            $table->string('icon', 64)->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specialties');
    }
};
