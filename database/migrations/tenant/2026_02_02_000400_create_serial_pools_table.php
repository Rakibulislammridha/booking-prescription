<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The three disjoint number ranges per session instance; the FOR UPDATE lock target of allocation (SCHEMA §3.3, §5.1).
// Layout: counter [1, C], online [C+1, C+O], buffer [C+O+1, C+O+B]; a zero quota is the empty range range_end = range_start - 1.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serial_pools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_instance_id')->constrained('session_instances')->cascadeOnDelete();
            $table->string('pool', 8);
            $table->integer('range_start');
            $table->integer('range_end');
            $table->integer('next_number');
            $table->integer('issued_count')->default(0);
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();

            $table->unique(['session_instance_id', 'pool'], 'serial_pools_session_instance_id_pool_uniq');
        });

        DB::statement("ALTER TABLE serial_pools ADD CONSTRAINT serial_pools_pool_check CHECK (pool IN ('online', 'counter', 'buffer'))");
        DB::statement('ALTER TABLE serial_pools ADD CONSTRAINT serial_pools_range_start_check CHECK (range_start >= 1)');
        DB::statement('ALTER TABLE serial_pools ADD CONSTRAINT serial_pools_range_end_check CHECK (range_end >= range_start - 1)');
        DB::statement('ALTER TABLE serial_pools ADD CONSTRAINT serial_pools_next_number_check CHECK (next_number BETWEEN range_start AND range_end + 1)');
        DB::statement('ALTER TABLE serial_pools ADD CONSTRAINT serial_pools_issued_count_check CHECK (issued_count >= 0)');
        // Half-open [start, end + 1): an empty pool is `empty` and never conflicts; requires btree_gist (2026_01_01_000100).
        DB::statement('ALTER TABLE serial_pools ADD CONSTRAINT serial_pools_range_excl EXCLUDE USING gist (session_instance_id WITH =, int4range(range_start, range_end + 1) WITH &&)');
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_pools');
    }
};
