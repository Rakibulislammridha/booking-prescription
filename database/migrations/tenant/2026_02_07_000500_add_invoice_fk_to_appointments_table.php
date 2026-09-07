<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// SCHEMA §5.11: `appointments.invoice_id` was created as a plain bigint by the Booking module (2026_02_03) because
// `invoices` did not exist yet. Billing owns the table, so Billing adds the FK.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_invoice_id_foreign');
    }
};
