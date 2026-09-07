<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.5 `cash_shifts` — the cash drawer per receptionist per branch (BRIEF §5.F). The partial unique index
// is the whole point: one open shift per user, enforced by Postgres, so two tabs cannot open two drawers.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 8)->default('open');
            $table->timestampTz('opened_at')->useCurrent();
            $table->timestampTz('closed_at')->nullable();
            $table->bigInteger('opening_float_paisa')->default(0);
            $table->bigInteger('expected_cash_paisa')->nullable();
            $table->bigInteger('counted_cash_paisa')->nullable();
            $table->bigInteger('variance_paisa')->nullable();
            $table->bigInteger('card_total_paisa')->nullable();
            $table->bigInteger('mobile_money_total_paisa')->nullable();
            $table->string('closing_note', 255)->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['user_id'], 'cash_shifts_user_id_idx');
            $table->index(['closed_by_user_id'], 'cash_shifts_closed_by_user_id_idx');
        });

        DB::statement('CREATE INDEX cash_shifts_branch_id_opened_at_idx ON cash_shifts (branch_id, opened_at DESC)');
        DB::statement("CREATE UNIQUE INDEX cash_shifts_user_id_uniq_p ON cash_shifts (user_id) WHERE status = 'open'");

        DB::statement("ALTER TABLE cash_shifts ADD CONSTRAINT cash_shifts_status_check CHECK (status IN ('open', 'closed'))");
        DB::statement('ALTER TABLE cash_shifts ADD CONSTRAINT cash_shifts_opening_float_paisa_check CHECK (opening_float_paisa >= 0)');
        DB::statement("ALTER TABLE cash_shifts ADD CONSTRAINT cash_shifts_closed_check CHECK ((status = 'closed') = (closed_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_shifts');
    }
};
