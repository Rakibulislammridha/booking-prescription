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
        Schema::create('holidays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->date('holiday_date');
            $table->string('name', 120);
            $table->string('name_bn', 160)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['branch_id'], 'holidays_branch_id_idx');
            $table->index(['created_by_user_id'], 'holidays_created_by_user_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX holidays_date_branch_uniq ON holidays (holiday_date, COALESCE(branch_id, 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
