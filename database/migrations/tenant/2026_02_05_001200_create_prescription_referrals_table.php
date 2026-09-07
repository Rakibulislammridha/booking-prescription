<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `prescription_referrals` (immutable with the parent).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
            $table->string('type', 20);
            $table->foreignId('referred_to_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->foreignId('external_diagnostic_centre_id')->nullable()->constrained('external_diagnostic_centres')->nullOnDelete();
            $table->string('referred_to_name', 200);
            $table->string('referred_to_specialty', 120)->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_urgent')->default(false);
            $table->timestampsTz();

            $table->index(['prescription_id'], 'prescription_referrals_prescription_id_idx');
            $table->index(['referred_to_doctor_id'], 'prescription_referrals_referred_to_doctor_id_idx');
            $table->index(['external_diagnostic_centre_id'], 'prescription_referrals_external_diagnostic_centre_id_idx');
        });

        DB::statement("ALTER TABLE prescription_referrals ADD CONSTRAINT prescription_referrals_type_check CHECK (type IN ('doctor', 'hospital', 'diagnostic_centre'))");
        DB::unprepared('CREATE TRIGGER prescription_children_immutable_trg BEFORE INSERT OR UPDATE OR DELETE ON prescription_referrals FOR EACH ROW EXECUTE FUNCTION public.fn_prescription_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_referrals');
    }
};
