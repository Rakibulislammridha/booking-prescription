<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->foreign('current_subscription_id', 'tenants_current_subscription_id_foreign')->references('id')->on('subscriptions')->nullOnDelete();
            $table->index(['current_subscription_id'], 'tenants_current_subscription_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropForeign('tenants_current_subscription_id_foreign');
            $table->dropIndex('tenants_current_subscription_id_idx');
        });
    }
};
