<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.6 `sms_gateway_settings`: per-tenant provider credentials for SMS / WhatsApp / IVR.
// `credentials` is ENC (`encrypted:array`); never a config value, never in a log or an audit diff.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_gateway_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 10)->default('sms');
            $table->string('provider', 32);
            $table->string('name', 80);
            $table->string('sender_id', 20)->nullable();
            $table->text('credentials');
            $table->jsonb('options')->default('{}');
            $table->smallInteger('priority')->default(10);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->bigInteger('balance_paisa')->nullable();
            $table->timestampTz('balance_checked_at')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['channel', 'is_active', 'priority'], 'sms_gateway_settings_channel_is_active_priority_idx');
            $table->index(['updated_by_user_id'], 'sms_gateway_settings_updated_by_user_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX sms_gateway_settings_channel_uniq_p ON sms_gateway_settings (channel) WHERE is_default');
        DB::statement("ALTER TABLE sms_gateway_settings ADD CONSTRAINT sms_gateway_settings_channel_check CHECK (channel IN ('sms', 'whatsapp', 'ivr'))");
        DB::statement("ALTER TABLE sms_gateway_settings ADD CONSTRAINT sms_gateway_settings_provider_check CHECK (provider IN ('ssl_wireless', 'bulksmsbd', 'grameenphone', 'banglalink', 'robi', 'infobip', 'twilio', 'whatsapp_cloud', 'custom_http'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_gateway_settings');
    }
};
