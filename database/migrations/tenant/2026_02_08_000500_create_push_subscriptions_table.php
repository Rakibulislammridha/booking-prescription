<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.6 `push_subscriptions`: Web Push endpoints for staff users and patients.
// `keys` is ENC; `endpoint_hash` (sha256 of the endpoint) carries the uniqueness because the endpoint is a long URL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('subscriber_type', 160);
            $table->unsignedBigInteger('subscriber_id');
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique('push_subscriptions_endpoint_hash_uniq');
            $table->text('keys');
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->text('user_agent')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->smallInteger('failed_count')->default(0);
            $table->timestampsTz();

            $table->index(['subscriber_type', 'subscriber_id'], 'push_subscriptions_subscriber_type_subscriber_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
