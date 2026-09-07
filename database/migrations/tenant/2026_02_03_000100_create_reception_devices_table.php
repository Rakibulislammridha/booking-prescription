<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Registered reception PWA installs and waiting-room display boxes — SCHEMA §3.3 `reception_devices`. The Sanctum
// tokenable of guard `device` (tokens live in the tenant personal_access_tokens through the tokenable morph).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reception_devices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('reception_devices_public_id_uniq');
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->smallInteger('number');
            $table->string('name', 80);
            $table->string('kind', 10)->default('reception');
            $table->string('device_fingerprint', 128);
            $table->string('device_secret_hash', 255)->nullable();   // unused / reserved (OFFLINE §13.1)
            $table->string('app_version', 20)->nullable();
            $table->foreignId('registered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 8)->default('active');
            $table->smallInteger('block_size')->default(5);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_sync_at')->nullable();
            $table->ipAddress('last_ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['device_fingerprint'], 'reception_devices_device_fingerprint_uniq');
            $table->unique(['branch_id', 'number'], 'reception_devices_branch_id_number_uniq');
            $table->index(['registered_by_user_id'], 'reception_devices_registered_by_user_id_idx');
        });

        DB::statement("CREATE INDEX reception_devices_branch_id_kind_idx_p ON reception_devices (branch_id, kind) WHERE status = 'active'");
        DB::statement("ALTER TABLE reception_devices ADD CONSTRAINT reception_devices_status_check CHECK (status IN ('active', 'revoked'))");
        DB::statement("ALTER TABLE reception_devices ADD CONSTRAINT reception_devices_kind_check CHECK (kind IN ('reception', 'display'))");
        DB::statement('ALTER TABLE reception_devices ADD CONSTRAINT reception_devices_block_size_check CHECK (block_size BETWEEN 1 AND 50)');
        DB::statement('ALTER TABLE reception_devices ADD CONSTRAINT reception_devices_number_check CHECK (number >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_devices');
    }
};
