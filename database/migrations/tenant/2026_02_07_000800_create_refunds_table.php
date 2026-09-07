<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.5 `refunds` — money out, always against one payment, always with a reason code (BRIEF §5.F).
// Offline devices may never create refunds (enforced in the app: OFFLINE §6.2, CONVENTIONS §7.6).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->bigInteger('amount_paisa');
            $table->string('method', 16);
            $table->string('status', 12)->default('pending');
            $table->string('reason_code', 24);
            $table->string('reason_note', 255)->nullable();
            $table->string('gateway_refund_id', 128)->nullable();
            $table->jsonb('gateway_payload')->default('{}');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->nullOnDelete();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->index(['invoice_id'], 'refunds_invoice_id_idx');
            $table->index(['payment_id'], 'refunds_payment_id_idx');
            $table->index(['cash_shift_id'], 'refunds_cash_shift_id_idx');
            $table->index(['requested_by_user_id'], 'refunds_requested_by_user_id_idx');
            $table->index(['approved_by_user_id'], 'refunds_approved_by_user_id_idx');
        });

        DB::statement("CREATE INDEX refunds_status_idx_p ON refunds (status) WHERE status = 'pending'");

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('pending', 'approved', 'processed', 'rejected', 'failed'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_reason_code_check CHECK (reason_code IN ('doctor_absent', 'patient_cancelled', 'duplicate', 'service_not_rendered', 'goodwill', 'other'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_method_check CHECK (method IN ('cash', 'card', 'bkash', 'nagad', 'sslcommerz', 'other'))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_paisa_check CHECK (amount_paisa > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
