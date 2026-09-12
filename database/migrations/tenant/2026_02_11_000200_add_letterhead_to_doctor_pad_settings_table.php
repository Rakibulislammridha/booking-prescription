<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRIEF §5.A — the pad's letterhead stops being one free-HTML box and becomes structured content.
 *
 * `letterhead` is the block a doctor designs — three palette colours, header lines, one to three footer columns.
 * Its shape is `App\Domain\Prescription\Data\Letterhead` (PRESCRIPTION.md §7.2.1), the one DTO the designer,
 * the writer's preview and the print partials all read the column through. `sample_path` is a photo or PDF of the
 * clinic's existing pad, kept on the private uploads disk purely as a tracing underlay in the designer: the sample
 * is NEVER printed and never reaches `pad_snapshot`.
 *
 * `header_html` / `footer_html` stay on the table but are no longer rendered by any print path; this column
 * replaced them, and they are kept for one release so nothing a doctor typed is lost.
 *
 * Both columns are added conditionally: the designer and the print rewrite landed in the same release from two
 * directions, and the column may already exist on a schema that took one of them first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_pad_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('doctor_pad_settings', 'letterhead')) {
                $table->jsonb('letterhead')->default('{}');
            }

            if (! Schema::hasColumn('doctor_pad_settings', 'sample_path')) {
                $table->string('sample_path', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('doctor_pad_settings', function (Blueprint $table): void {
            foreach (['letterhead', 'sample_path'] as $column) {
                if (Schema::hasColumn('doctor_pad_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
