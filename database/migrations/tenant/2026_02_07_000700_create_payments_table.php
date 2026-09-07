<?php

declare(strict_types=1);

use App\Tenancy\Facades\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.5 `payments` + the per-schema `receipt_number_seq` (SCHEMA §0.4). Three database-level guards make a
// double charge impossible however often a request or an offline event is replayed:
//   1. UNIQUE (idempotency_key)                                   — the desk / API retry
//   2. UNIQUE (reception_device_id, client_event_id) partial      — the offline event-log replay (OFFLINE §7)
//   3. UNIQUE (gateway, gateway_txn_id) partial                   — the gateway callback/webhook replay
// A row is never deleted or re-amounted; a reversal is a `refunds` row.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS receipt_number_seq START WITH 1 INCREMENT BY 1');

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('payments_public_id_uniq');
            $table->foreignId('tenant_id')->constrained('public.tenants')->restrictOnDelete();   // isolation assertion (SCHEMA §5.9)
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('receipt_number', 24)->nullable();
            $table->string('method', 16);
            $table->string('status', 20)->default('pending');
            $table->bigInteger('amount_paisa');
            $table->bigInteger('refunded_paisa')->default(0);
            $table->string('gateway', 16)->nullable();
            $table->string('gateway_txn_id', 128)->nullable();
            $table->string('gateway_payment_ref', 128)->nullable();
            $table->jsonb('gateway_payload')->default('{}');
            $table->string('idempotency_key', 64);
            $table->char('client_event_id', 26)->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reception_device_id')->nullable()->constrained('reception_devices')->nullOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->nullOnDelete();
            $table->timestampTz('paid_at')->nullable();
            $table->string('failed_reason', 255)->nullable();
            $table->timestampsTz();

            $table->unique(['idempotency_key'], 'payments_idempotency_key_uniq');
            $table->index(['tenant_id'], 'payments_tenant_id_idx');
            $table->index(['invoice_id'], 'payments_invoice_id_idx');
            $table->index(['patient_id'], 'payments_patient_id_idx');
            $table->index(['cash_shift_id'], 'payments_cash_shift_id_idx');
            $table->index(['paid_at'], 'payments_paid_at_idx');
            $table->index(['received_by_user_id'], 'payments_received_by_user_id_idx');
            $table->index(['reception_device_id'], 'payments_reception_device_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX payments_reception_device_id_client_event_id_uniq_p ON payments (reception_device_id, client_event_id) WHERE client_event_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX payments_receipt_number_uniq_p ON payments (receipt_number) WHERE receipt_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX payments_gateway_gateway_txn_id_uniq_p ON payments (gateway, gateway_txn_id) WHERE gateway_txn_id IS NOT NULL');

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('cash', 'card', 'bkash', 'nagad', 'sslcommerz', 'other'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending', 'succeeded', 'failed', 'cancelled', 'refunded', 'partially_refunded'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_gateway_check CHECK (gateway IS NULL OR gateway IN ('bkash', 'nagad', 'sslcommerz'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_paisa_check CHECK (amount_paisa > 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_refunded_paisa_check CHECK (refunded_paisa BETWEEN 0 AND amount_paisa)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_gateway_check CHECK ((method IN ('bkash', 'nagad', 'sslcommerz')) = (gateway IS NOT NULL))");
        DB::statement(sprintf('ALTER TABLE payments ADD CONSTRAINT payments_tenant_id_check CHECK (tenant_id = %d)', Tenancy::id() ?? throw new RuntimeException('payments migration must run inside Tenancy::run()')));
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        DB::statement('DROP SEQUENCE IF EXISTS receipt_number_seq');
    }
};
