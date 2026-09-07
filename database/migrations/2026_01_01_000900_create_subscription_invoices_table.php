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
        DB::statement('CREATE SEQUENCE IF NOT EXISTS subscription_invoice_seq');

        Schema::create('subscription_invoices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('subscription_invoices_public_id_uniq');
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('number', 32)->unique('subscription_invoices_number_uniq');
            $table->string('status', 32)->default('draft');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->bigInteger('subtotal_paisa')->default(0);
            $table->bigInteger('discount_paisa')->default(0);
            $table->bigInteger('tax_paisa')->default(0);
            $table->bigInteger('total_paisa')->default(0);
            $table->bigInteger('paid_paisa')->default(0);
            $table->jsonb('line_items')->default('[]');
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->smallInteger('dunning_step')->default(0);
            $table->string('pdf_path', 255)->nullable();
            $table->timestampsTz();

            $table->index(['subscription_id'], 'subscription_invoices_subscription_id_idx');
            $table->index(['status', 'due_at'], 'subscription_invoices_status_due_at_idx');
        });

        DB::statement('CREATE INDEX subscription_invoices_tenant_id_issued_at_idx ON subscription_invoices (tenant_id, issued_at DESC)');
        DB::statement("ALTER TABLE subscription_invoices ADD CONSTRAINT subscription_invoices_status_check CHECK (status IN ('draft', 'issued', 'paid', 'overdue', 'void'))");
        DB::statement('ALTER TABLE subscription_invoices ADD CONSTRAINT subscription_invoices_amounts_check CHECK (subtotal_paisa >= 0 AND discount_paisa >= 0 AND tax_paisa >= 0 AND total_paisa >= 0 AND paid_paisa >= 0 AND paid_paisa <= total_paisa)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
        DB::statement('DROP SEQUENCE IF EXISTS subscription_invoice_seq');
    }
};
