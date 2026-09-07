<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Extensions are database-scoped: visible from every tenant schema (SCHEMA §0.4, §5.1.2).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        // Extensions are shared infrastructure; never dropped by a rollback.
    }
};
