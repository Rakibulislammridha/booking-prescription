<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRIEF §5.A asks for departments in English and Bangla, exactly as `specialties` already carries them. The
 * create migration is merged (CONVENTIONS §3.1: never edit a merged migration), so the column arrives here, at
 * the end of the foundation's own tenant range, and SCHEMA §3.1 records it on `departments`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->string('name_bn', 160)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('name_bn');
        });
    }
};
