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
        Schema::create('doctors', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('doctors_public_id_uniq');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->string('name_bn', 200)->nullable();
            $table->string('slug', 80)->unique('doctors_slug_uniq');
            $table->string('code', 8)->unique('doctors_code_uniq');
            $table->string('gender', 8)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('photo_path', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('accepts_online_booking')->default(true);
            $table->boolean('accepts_telemedicine')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->string('room_label', 40)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['department_id'], 'doctors_department_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX doctors_user_id_uniq_p ON doctors (user_id) WHERE user_id IS NOT NULL');
        DB::statement("ALTER TABLE doctors ADD CONSTRAINT doctors_gender_check CHECK (gender IS NULL OR gender IN ('male', 'female', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
