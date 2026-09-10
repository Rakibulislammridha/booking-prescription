<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `public.tenants.platform_notes` (SCHEMA §2.1): free text the platform team keeps about a clinic — who the
 * contact really is, what was negotiated, why a domain is stuck. Written only from the super console's Edit
 * screen and never sent to the clinic's own surfaces (HandleInertiaRequests::tenant() does not read it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->text('platform_notes')->nullable()->after('branding');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('platform_notes');
        });
    }
};
