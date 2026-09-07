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
        Schema::create('doctor_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->unique('doctor_profiles_doctor_id_uniq')->constrained('doctors')->cascadeOnDelete();
            $table->string('degrees', 255)->nullable();
            $table->string('degrees_bn', 255)->nullable();
            $table->string('bmdc_reg_no', 32)->nullable();
            $table->string('designation', 160)->nullable();
            $table->text('bio')->nullable();
            $table->text('bio_bn')->nullable();
            $table->smallInteger('experience_years')->nullable();
            $table->jsonb('languages')->default('["bn","en"]');
            $table->bigInteger('new_fee_paisa')->default(0);
            $table->bigInteger('followup_fee_paisa')->default(0);
            $table->smallInteger('free_followup_within_days')->default(0);
            $table->smallInteger('followup_within_days')->default(30);
            $table->boolean('report_visit_free')->default(true);
            $table->bigInteger('telemedicine_fee_paisa')->nullable();
            $table->bigInteger('online_booking_fee_delta_paisa')->default(0);
            $table->boolean('advance_payment_required')->default(false);
            $table->text('chamber_notes')->nullable();
            $table->jsonb('prefs')->default('{}');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE doctor_profiles ADD CONSTRAINT doctor_profiles_fees_check CHECK (new_fee_paisa >= 0 AND followup_fee_paisa >= 0 AND (telemedicine_fee_paisa IS NULL OR telemedicine_fee_paisa >= 0))');
        DB::statement('ALTER TABLE doctor_profiles ADD CONSTRAINT doctor_profiles_followup_window_check CHECK (free_followup_within_days <= followup_within_days)');
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_profiles');
    }
};
