<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The foundation created audit_logs.patient_id as a plain indexed bigint (2026_02_01_001500); the FK belongs to
// the module that owns `patients` (CONVENTIONS §3.1). ON DELETE SET NULL per SCHEMA §3.7.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreign('patient_id', 'audit_logs_patient_id_foreign')->references('id')->on('patients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropForeign('audit_logs_patient_id_foreign');
        });
    }
};
