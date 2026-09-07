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
        Schema::create('subscription_payments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('subscription_payments_public_id_uniq');
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('subscription_invoice_id')->constrained('subscription_invoices')->restrictOnDelete();
            $table->string('method', 32);
            $table->string('status', 32)->default('pending');
            $table->bigInteger('amount_paisa');
            $table->string('gateway_txn_id', 128)->nullable();
            $table->jsonb('gateway_payload')->default('{}');
            $table->string('idempotency_key', 64)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->foreignId('recorded_by_super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id'], 'subscription_payments_tenant_id_idx');
            $table->index(['subscription_invoice_id'], 'subscription_payments_subscription_invoice_id_idx');
            $table->index(['recorded_by_super_admin_id'], 'subscription_payments_recorded_by_super_admin_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX subscription_payments_idempotency_key_uniq_p ON subscription_payments (idempotency_key) WHERE idempotency_key IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX subscription_payments_method_gateway_txn_id_uniq_p ON subscription_payments (method, gateway_txn_id) WHERE gateway_txn_id IS NOT NULL');
        DB::statement("ALTER TABLE subscription_payments ADD CONSTRAINT subscription_payments_method_check CHECK (method IN ('bkash', 'nagad', 'sslcommerz', 'bank_transfer', 'cash', 'manual'))");
        DB::statement("ALTER TABLE subscription_payments ADD CONSTRAINT subscription_payments_status_check CHECK (status IN ('pending', 'succeeded', 'failed', 'refunded'))");
        DB::statement('ALTER TABLE subscription_payments ADD CONSTRAINT subscription_payments_amount_paisa_check CHECK (amount_paisa > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
