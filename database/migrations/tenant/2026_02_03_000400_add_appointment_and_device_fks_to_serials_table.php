<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Circular references resolved after `appointments` and `reception_devices` exist (SCHEMA §5.11, S's serials migration
// left both columns as plain nullable bigints): serials.appointment_id → appointments SET NULL,
// serials.reception_device_id → reception_devices SET NULL, serial_events.reception_device_id → reception_devices SET NULL.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE serials ADD CONSTRAINT serials_appointment_id_foreign FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE serials ADD CONSTRAINT serials_reception_device_id_foreign FOREIGN KEY (reception_device_id) REFERENCES reception_devices (id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE serial_events ADD CONSTRAINT serial_events_reception_device_id_foreign FOREIGN KEY (reception_device_id) REFERENCES reception_devices (id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE serial_events DROP CONSTRAINT IF EXISTS serial_events_reception_device_id_foreign');
        DB::statement('ALTER TABLE serials DROP CONSTRAINT IF EXISTS serials_reception_device_id_foreign');
        DB::statement('ALTER TABLE serials DROP CONSTRAINT IF EXISTS serials_appointment_id_foreign');
    }
};
