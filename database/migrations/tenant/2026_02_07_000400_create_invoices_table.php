<?php

declare(strict_types=1);

use App\Tenancy\Facades\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.5 `invoices` + the per-schema `invoice_number_seq` (SCHEMA §0.4). Money is bigint paisa; `due_paisa`
// is GENERATED so the outstanding balance can never drift from total − paid, and `paid_paisa <= total_paisa`
// is a CHECK so no code path can over-collect. Corrections are new rows (discounts / refunds / a new invoice),
// never edits of a frozen one.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS invoice_number_seq START WITH 1 INCREMENT BY 1');

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('invoices_public_id_uniq');
            $table->foreignId('tenant_id')->constrained('public.tenants')->restrictOnDelete();   // isolation assertion (SCHEMA §5.9)
            $table->string('number', 24)->unique('invoices_number_uniq');
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->bigInteger('subtotal_paisa')->default(0);
            $table->bigInteger('discount_paisa')->default(0);
            $table->bigInteger('coupon_discount_paisa')->default(0);
            $table->bigInteger('vat_paisa')->default(0);
            $table->bigInteger('total_paisa')->default(0);
            $table->bigInteger('paid_paisa')->default(0);
            $table->bigInteger('due_paisa')->storedAs('total_paisa - paid_paisa');
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id'], 'invoices_tenant_id_idx');
            $table->index(['appointment_id'], 'invoices_appointment_id_idx');
            $table->index(['visit_id'], 'invoices_visit_id_idx');
            $table->index(['created_by_user_id'], 'invoices_created_by_user_id_idx');
            $table->index(['cash_shift_id'], 'invoices_cash_shift_id_idx');
        });

        DB::statement('CREATE INDEX invoices_patient_id_issued_at_idx ON invoices (patient_id, issued_at DESC)');
        DB::statement('CREATE INDEX invoices_branch_id_issued_at_idx ON invoices (branch_id, issued_at)');
        DB::statement('CREATE INDEX invoices_doctor_id_issued_at_idx ON invoices (doctor_id, issued_at)');
        DB::statement('CREATE INDEX invoices_status_idx_p ON invoices (status) WHERE due_paisa > 0');
        // One live invoice per appointment: a retry of "create the invoice" can never mint a second bill.
        DB::statement("CREATE UNIQUE INDEX invoices_appointment_id_uniq_p ON invoices (appointment_id) WHERE appointment_id IS NOT NULL AND status <> 'void'");

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('draft', 'issued', 'partially_paid', 'paid', 'void', 'refunded'))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_subtotal_paisa_check CHECK (subtotal_paisa >= 0)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_discount_paisa_check CHECK (discount_paisa >= 0)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_coupon_discount_paisa_check CHECK (coupon_discount_paisa >= 0)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_vat_paisa_check CHECK (vat_paisa >= 0)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_total_paisa_check CHECK (total_paisa >= 0)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_paid_paisa_check CHECK (paid_paisa >= 0)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_paid_not_over_total_check CHECK (paid_paisa <= total_paisa)');
        // Per-schema isolation assertion (SCHEMA §5.9): the tenant id is known because tenant migrations run inside Tenancy::run().
        DB::statement(sprintf('ALTER TABLE invoices ADD CONSTRAINT invoices_tenant_id_check CHECK (tenant_id = %d)', Tenancy::id() ?? throw new RuntimeException('invoices migration must run inside Tenancy::run()')));
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        DB::statement('DROP SEQUENCE IF EXISTS invoice_number_seq');
    }
};
