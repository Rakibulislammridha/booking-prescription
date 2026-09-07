<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Circular reference resolved after `serials` exists (SCHEMA §5.11): session_instances.now_serving_serial_id → serials(id) ON DELETE SET NULL.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE session_instances ADD CONSTRAINT session_instances_now_serving_serial_id_foreign FOREIGN KEY (now_serving_serial_id) REFERENCES serials (id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE session_instances DROP CONSTRAINT IF EXISTS session_instances_now_serving_serial_id_foreign');
    }
};
