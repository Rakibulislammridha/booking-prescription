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
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique('plans_code_uniq');
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->bigInteger('price_monthly_paisa')->default(0);
            $table->bigInteger('price_yearly_paisa')->default(0);
            $table->smallInteger('trial_days')->default(14);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_addon')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_price_monthly_paisa_check CHECK (price_monthly_paisa >= 0)');
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_price_yearly_paisa_check CHECK (price_yearly_paisa >= 0)');
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_trial_days_check CHECK (trial_days >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
