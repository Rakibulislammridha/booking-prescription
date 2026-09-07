<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('branches_public_id_uniq');
            $table->string('name', 160);
            $table->string('code', 8)->unique('branches_code_uniq');
            $table->string('slug', 80)->unique('branches_slug_uniq');
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            $table->jsonb('geo')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
