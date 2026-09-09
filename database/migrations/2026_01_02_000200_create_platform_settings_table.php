<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `public.platform_settings` (SCHEMA §2.19): the control plane's own key/value store, the counterpart of the
 * tenant `settings` table. Keys are the closed registry `App\Domain\SaaS\Support\PlatformSettingsRegistry`; a
 * missing row means the registry default. First key: `security.super_two_factor` (ARCHITECTURE §6.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 96)->unique('platform_settings_key_uniq');
            $table->jsonb('value');
            $table->foreignId('updated_by_super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['updated_by_super_admin_id'], 'platform_settings_updated_by_super_admin_id_idx');
        });

        // Keys are dotted lowercase identifiers (`security.super_two_factor`); the registry validates the closed
        // list in code, the CHECK only stops a stray row shape from a hand-written insert.
        DB::statement("ALTER TABLE platform_settings ADD CONSTRAINT platform_settings_key_check CHECK (key ~ '^[a-z0-9_]+(\\.[a-z0-9_]+)+$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
