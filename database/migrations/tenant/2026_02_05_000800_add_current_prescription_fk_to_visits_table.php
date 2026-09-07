<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA §5.11: `ALTER visits ADD FK current_prescription_id` after `prescriptions` (circular reference).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->foreign('current_prescription_id', 'visits_current_prescription_id_foreign')->references('id')->on('prescriptions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropForeign('visits_current_prescription_id_foreign');
        });
    }
};
