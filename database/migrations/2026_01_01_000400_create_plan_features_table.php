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
        Schema::create('plan_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature_key', 64);
            $table->bigInteger('limit_value')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            $table->unique(['plan_id', 'feature_key'], 'plan_features_plan_id_feature_key_uniq');
            $table->index(['plan_id'], 'plan_features_plan_id_idx');
        });

        DB::statement('ALTER TABLE plan_features ADD CONSTRAINT plan_features_limit_value_check CHECK (limit_value IS NULL OR limit_value >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
