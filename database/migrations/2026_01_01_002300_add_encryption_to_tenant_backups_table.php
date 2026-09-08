<?php

declare(strict_types=1);

use App\Domain\SaaS\Enums\BackupEncryption;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How each dump is protected at rest (SCHEMA §2.11, ARCHITECTURE §8.2).
 *
 * A dedicated column rather than a suffix on `storage_path` or a note in `error`: "which of these objects is
 * readable by anyone who gets the bucket" is a question an operator answers with a WHERE clause, and it must stay
 * true for rows written before the key existed. Every existing row IS plaintext, so `none` is the honest default
 * and the honest backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_backups', function (Blueprint $table): void {
            $table->string('encryption', 32)->default(BackupEncryption::None->value)->after('storage_path');
        });

        DB::statement(
            "ALTER TABLE tenant_backups ADD CONSTRAINT tenant_backups_encryption_check CHECK (encryption IN ('"
            .implode("', '", BackupEncryption::values())."'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenant_backups DROP CONSTRAINT IF EXISTS tenant_backups_encryption_check');

        Schema::table('tenant_backups', function (Blueprint $table): void {
            $table->dropColumn('encryption');
        });
    }
};
