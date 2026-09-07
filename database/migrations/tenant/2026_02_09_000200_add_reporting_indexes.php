<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Reports §5.L — three indexes the analytics queries need and the owning modules had no reason to create.
// Each module's own indexes are all doctor- or patient-leading (`visits (doctor_id, started_at DESC)`,
// `prescriptions (doctor_id, issued_at DESC)`), because their screens always know whose record they are
// showing. A clinic-wide report knows only a date range, so without these it degrades to a sequential scan
// over three years of visits. CONVENTIONS §3.1 puts a later module's need for an earlier module's table in
// the later module's own migration — this is that migration.
return new class extends Migration
{
    public function up(): void
    {
        // New-vs-returning, top diagnoses and the follow-up funnel scan visits by period across all doctors.
        DB::statement('CREATE INDEX visits_started_at_idx ON visits (started_at)');

        // Follow-up compliance reads only the advised visits; the partial index is a fraction of the table.
        DB::statement('CREATE INDEX visits_follow_up_on_idx_p ON visits (follow_up_on) WHERE follow_up_on IS NOT NULL');

        // Top drugs counts issued prescriptions by period; superseded and draft versions never take part.
        DB::statement("CREATE INDEX prescriptions_issued_at_idx_p ON prescriptions (issued_at) WHERE status = 'issued'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prescriptions_issued_at_idx_p');
        DB::statement('DROP INDEX IF EXISTS visits_follow_up_on_idx_p');
        DB::statement('DROP INDEX IF EXISTS visits_started_at_idx');
    }
};
