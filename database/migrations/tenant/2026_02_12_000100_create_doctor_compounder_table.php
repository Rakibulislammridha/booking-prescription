<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which compounder works which doctor's desk — SCHEMA §3.1 `doctor_compounder`. This pivot IS the boundary the
// brief asks for ("he can't be able to see other doctors' patient lists"): App\Domain\Clinic\Services\DoctorScope
// reads it on every request, and every board, list and policy narrows through that one answer.
//
// Many-to-many on purpose: a clinic may put one compounder behind two doctors (a shared desk in a small chamber),
// and a doctor may keep two (a morning and an evening hand). The unique key makes a re-assign idempotent.
//
// DEPENDENCIES: 2026_02_01_000400 (`users`) and 2026_02_01_000800 (`doctors`) — both earlier prefixes, so this
// file may FK to them (CONVENTIONS §3.1). No `public_id`: the row is never addressed from a URL or a channel; the
// screen addresses the doctor and the user by theirs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_compounder', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Who assigned it survives the assigner leaving the clinic: the trail is worth more than the name.
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['doctor_id', 'user_id'], 'doctor_compounder_doctor_id_user_id_uniq');
            // The hot read is the reverse one — "which doctors is THIS signed-in compounder scoped to" — once per request.
            $table->index(['user_id'], 'doctor_compounder_user_id_idx');
            $table->index(['assigned_by_user_id'], 'doctor_compounder_assigned_by_user_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_compounder');
    }
};
