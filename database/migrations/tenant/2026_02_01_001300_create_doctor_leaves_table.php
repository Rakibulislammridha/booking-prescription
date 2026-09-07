<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_leaves', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('type', 16)->default('planned');
            $table->string('reason', 255)->nullable();
            $table->boolean('notify_patients')->default(true);
            $table->timestampTz('notified_at')->nullable();
            $table->boolean('is_cancelled')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['doctor_id', 'starts_on', 'ends_on'], 'doctor_leaves_doctor_id_starts_on_ends_on_idx');
            $table->index(['branch_id'], 'doctor_leaves_branch_id_idx');
            $table->index(['created_by_user_id'], 'doctor_leaves_created_by_user_id_idx');
        });

        DB::statement('ALTER TABLE doctor_leaves ADD CONSTRAINT doctor_leaves_range_check CHECK (ends_on >= starts_on)');
        DB::statement("ALTER TABLE doctor_leaves ADD CONSTRAINT doctor_leaves_type_check CHECK (type IN ('planned', 'emergency'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_leaves');
    }
};
