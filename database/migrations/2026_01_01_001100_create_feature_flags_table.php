<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pennant 1.26 'features' shape, renamed; config('pennant.stores.database.table') = 'public.feature_flags'.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('scope');
            $table->text('value');
            $table->timestampsTz();

            $table->unique(['name', 'scope'], 'feature_flags_name_scope_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
