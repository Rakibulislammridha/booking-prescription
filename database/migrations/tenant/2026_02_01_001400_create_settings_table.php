<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 96)->unique('settings_key_uniq');
            $table->jsonb('value');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['updated_by_user_id'], 'settings_updated_by_user_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
