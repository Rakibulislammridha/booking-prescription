<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// serial_blocks.reception_device_id → reception_devices(id) ON DELETE RESTRICT (SCHEMA §3.3; the column shape — nullable
// bigint, NULL = desk-owned released range — was created by S's 2026_02_02_000500 migration and is unchanged).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE serial_blocks ADD CONSTRAINT serial_blocks_reception_device_id_foreign FOREIGN KEY (reception_device_id) REFERENCES reception_devices (id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE serial_blocks DROP CONSTRAINT IF EXISTS serial_blocks_reception_device_id_foreign');
    }
};
