<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `public.platform_messages` — the outbound ledger of messages the PLATFORM sends (welcome, dunning, suspension,
 * receipts, catalogue notices, console test sends). The Notifications module's ledger (`notifications`,
 * `notification_logs`) lives in each tenant schema and records what a CLINIC sends to its patients; nothing there
 * ever sees a dunning email, so the console had no way to answer "did the final notice reach this owner". This is
 * the minimal central counterpart: one row per attempt, recipient MASKED (CONVENTIONS §11: no PII in logs), body
 * never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('channel', 8);                     // email | sms
            $table->string('kind', 32);                       // welcome | dunning | dunning_final | suspended | receipt | reconciliation | reconciliation_tenant | promotion_rejected | test
            $table->string('recipient', 255);                 // masked: r***@clinic.test / +88017****123
            $table->string('subject', 255)->nullable();
            $table->char('locale', 2)->default('en');
            $table->string('status', 10);                     // sent | failed | rejected
            $table->string('provider', 32)->nullable();
            $table->text('error')->nullable();
            $table->foreignId('sent_by_super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['created_at'], 'platform_messages_created_at_idx');
            $table->index(['tenant_id', 'created_at'], 'platform_messages_tenant_created_at_idx');
            $table->index(['kind'], 'platform_messages_kind_idx');
        });

        DB::statement("ALTER TABLE platform_messages ADD CONSTRAINT platform_messages_channel_check CHECK (channel IN ('email', 'sms'))");
        DB::statement("ALTER TABLE platform_messages ADD CONSTRAINT platform_messages_status_check CHECK (status IN ('sent', 'failed', 'rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_messages');
    }
};
