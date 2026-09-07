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
        Schema::create('usage_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('metric', 48);
            $table->string('period', 7)->default('current');
            $table->bigInteger('value')->default(0);
            $table->bigInteger('limit_snapshot')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'metric', 'period'], 'usage_counters_tenant_id_metric_period_uniq');
        });

        DB::statement('ALTER TABLE usage_counters ADD CONSTRAINT usage_counters_value_check CHECK (value >= 0)');
        DB::statement("ALTER TABLE usage_counters ADD CONSTRAINT usage_counters_period_check CHECK (period = 'current' OR period ~ '^\\d{4}-(0[1-9]|1[0-2])$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
