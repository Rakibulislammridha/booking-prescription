<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Device leases and desk-owned released ranges (the free-list) carved out of a pool — SCHEMA §3.3 `serial_blocks`.
// DEPENDENCY (engineer R, 2026_02_03_*): `reception_devices` does not exist yet, so `reception_device_id` is a plain
// nullable bigint here (indexed). R adds `FOREIGN KEY (reception_device_id) REFERENCES reception_devices(id) ON DELETE RESTRICT`
// in their own migration. The column shape (nullable bigint) is exactly SCHEMA §3.3 and must not change.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serial_blocks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('serial_blocks_public_id_uniq');
            $table->foreignId('session_instance_id')->constrained('session_instances')->cascadeOnDelete();
            $table->foreignId('serial_pool_id')->constrained('serial_pools')->cascadeOnDelete();
            $table->bigInteger('reception_device_id')->nullable();
            $table->integer('range_start');
            $table->integer('range_end');
            $table->integer('next_number');
            $table->string('status', 10)->default('active');
            $table->timestampTz('leased_at')->useCurrent();
            $table->foreignId('leased_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->smallInteger('returned_count')->default(0);
            $table->timestampsTz();

            $table->index(['serial_pool_id'], 'serial_blocks_serial_pool_id_idx');
            $table->index(['leased_by_user_id'], 'serial_blocks_leased_by_user_id_idx');
            $table->index(['reception_device_id', 'session_instance_id', 'status'], 'serial_blocks_reception_device_id_session_instance_id_status_idx');
        });

        DB::statement("CREATE INDEX serial_blocks_session_instance_id_range_start_idx_p ON serial_blocks (session_instance_id, range_start) WHERE status = 'released'");
        DB::statement("ALTER TABLE serial_blocks ADD CONSTRAINT serial_blocks_status_check CHECK (status IN ('active', 'released', 'exhausted'))");
        DB::statement('ALTER TABLE serial_blocks ADD CONSTRAINT serial_blocks_range_end_check CHECK (range_end >= range_start - 1)');
        DB::statement('ALTER TABLE serial_blocks ADD CONSTRAINT serial_blocks_next_number_check CHECK (next_number BETWEEN range_start AND range_end + 1)');
        DB::statement("ALTER TABLE serial_blocks ADD CONSTRAINT serial_blocks_active_device_check CHECK (status <> 'active' OR reception_device_id IS NOT NULL)");
        DB::statement("ALTER TABLE serial_blocks ADD CONSTRAINT serial_blocks_revoked_check CHECK (revoked_at IS NULL OR status <> 'active')");
        DB::statement('ALTER TABLE serial_blocks ADD CONSTRAINT serial_blocks_returned_count_check CHECK (returned_count >= 0)');
        // Half-open like the pools: a row shrunk to nothing is `empty`; splitting a released row around a replayed number stays disjoint.
        DB::statement('ALTER TABLE serial_blocks ADD CONSTRAINT serial_blocks_range_excl EXCLUDE USING gist (session_instance_id WITH =, int4range(range_start, range_end + 1) WITH &&)');
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_blocks');
    }
};
