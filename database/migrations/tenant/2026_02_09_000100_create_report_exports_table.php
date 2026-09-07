<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Reports §5.L — the receipt of a queued export. A small report streams straight down the response; anything
// past ReportExporter::SYNC_ROW_LIMIT is handed to the `reports` queue, and this row is what the owner comes
// back to when the file is ready. It stores the FILTERS, never the numbers: re-reading it is a download, not a
// re-computation, so a file can never disagree with the audit row that recorded who asked for it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('report', 32);
            $table->string('format', 8);
            $table->string('status', 12)->default('pending');
            $table->jsonb('filters')->default('{}');
            $table->string('title', 160);
            $table->integer('row_count')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at'], 'report_exports_user_id_created_at_idx');
        });

        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_check CHECK (status IN ('pending', 'processing', 'ready', 'failed'))");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_format_check CHECK (format IN ('csv', 'xlsx', 'pdf'))");
        DB::statement('ALTER TABLE report_exports ADD CONSTRAINT report_exports_row_count_check CHECK (row_count IS NULL OR row_count >= 0)');
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_ready_check CHECK (status <> 'ready' OR file_path IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
