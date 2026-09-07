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
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status', 32)->default('trialing');
            $table->string('billing_cycle', 16)->default('monthly');
            $table->bigInteger('price_paisa');
            $table->timestampTz('current_period_start');
            $table->timestampTz('current_period_end');
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('grace_until')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->jsonb('feature_overrides')->default('{}');
            $table->timestampsTz();

            $table->index(['tenant_id'], 'subscriptions_tenant_id_idx');
            $table->index(['plan_id'], 'subscriptions_plan_id_idx');
            $table->index(['status', 'current_period_end'], 'subscriptions_status_current_period_end_idx');
        });

        DB::statement("CREATE UNIQUE INDEX subscriptions_tenant_id_plan_id_uniq_p ON subscriptions (tenant_id, plan_id) WHERE status IN ('trialing', 'active', 'past_due', 'suspended')");
        DB::statement("CREATE INDEX subscriptions_grace_until_idx_p ON subscriptions (grace_until) WHERE status = 'past_due'");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('trialing', 'active', 'past_due', 'suspended', 'cancelled', 'expired'))");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_billing_cycle_check CHECK (billing_cycle IN ('monthly', 'yearly'))");
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_period_check CHECK (current_period_end > current_period_start)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
