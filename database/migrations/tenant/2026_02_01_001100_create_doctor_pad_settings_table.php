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
        Schema::create('doctor_pad_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->unique('doctor_pad_settings_doctor_id_uniq')->constrained('doctors')->cascadeOnDelete();
            $table->string('paper_size', 4)->default('A5');
            $table->string('orientation', 10)->default('portrait');
            $table->boolean('letterhead_enabled')->default(true);
            $table->boolean('preprinted_mode')->default(false);
            $table->string('logo_path', 255)->nullable();
            $table->text('header_html')->nullable();
            $table->text('footer_html')->nullable();
            $table->jsonb('margins')->default('{"top":20,"right":15,"bottom":20,"left":15}');
            $table->smallInteger('header_height_mm')->default(35);
            $table->smallInteger('footer_height_mm')->default(20);
            $table->string('font_family', 64)->default('Noto Sans Bengali');
            $table->decimal('font_size_pt', 4, 1)->default(10.5);
            $table->boolean('show_qr')->default(true);
            $table->boolean('show_vitals')->default(true);
            $table->boolean('show_drug_info_url')->default(true);
            $table->jsonb('layout')->default('{}');
            $table->string('token_slip_template', 32)->default('thermal_58');
            $table->string('default_language', 5)->default('both');
            $table->string('signature_path', 255)->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE doctor_pad_settings ADD CONSTRAINT doctor_pad_settings_paper_size_check CHECK (paper_size IN ('A4', 'A5'))");
        DB::statement("ALTER TABLE doctor_pad_settings ADD CONSTRAINT doctor_pad_settings_orientation_check CHECK (orientation IN ('portrait', 'landscape'))");
        DB::statement("ALTER TABLE doctor_pad_settings ADD CONSTRAINT doctor_pad_settings_token_slip_template_check CHECK (token_slip_template IN ('thermal_58', 'thermal_80', 'a5'))");
        DB::statement("ALTER TABLE doctor_pad_settings ADD CONSTRAINT doctor_pad_settings_default_language_check CHECK (default_language IN ('bn', 'en', 'both'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_pad_settings');
    }
};
