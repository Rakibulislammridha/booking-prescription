<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.6 `notification_logs`: one row per delivery attempt with the sanitised provider exchange.
// No serial or template column — per-serial dedupe queries go to `notifications`. created_at only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->smallInteger('attempt_no');
            $table->string('provider', 32);
            $table->string('provider_message_id', 128)->nullable();
            $table->string('status', 10);
            $table->jsonb('request')->nullable();
            $table->jsonb('response')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->integer('latency_ms')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['notification_id', 'attempt_no'], 'notification_logs_notification_id_attempt_no_uniq');
        });

        DB::statement('CREATE UNIQUE INDEX notification_logs_provider_provider_message_id_uniq_p ON notification_logs (provider, provider_message_id) WHERE provider_message_id IS NOT NULL');
        DB::statement("ALTER TABLE notification_logs ADD CONSTRAINT notification_logs_status_check CHECK (status IN ('sent', 'delivered', 'failed', 'rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
