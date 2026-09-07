<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA §5.11: `ALTER appointments ADD FK follow_up_of_visit_id` runs after `visits`. Guarded: R's appointments
// migration (2026_02_03_000200) is untracked at the time of writing; when it is absent nothing happens here.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('appointments') || ! Schema::hasColumn('appointments', 'follow_up_of_visit_id')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreign('follow_up_of_visit_id', 'appointments_follow_up_of_visit_id_foreign')->references('id')->on('visits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('appointments')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign('appointments_follow_up_of_visit_id_foreign');
        });
    }
};
