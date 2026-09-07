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
        Schema::create('doctor_specialties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('specialty_id')->constrained('specialties')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('created_at')->nullable();

            $table->unique(['doctor_id', 'specialty_id'], 'doctor_specialties_doctor_id_specialty_id_uniq');
            $table->index(['specialty_id'], 'doctor_specialties_specialty_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX doctor_specialties_doctor_id_uniq_p ON doctor_specialties (doctor_id) WHERE is_primary');
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_specialties');
    }
};
