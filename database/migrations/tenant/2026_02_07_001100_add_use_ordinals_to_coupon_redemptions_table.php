<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.5. The redemption caps (`coupons.max_uses`, `coupons.max_uses_per_patient`) had no database backstop:
// `coupon_redemptions_invoice_id_uniq` stops a double-submit on ONE invoice and nothing else, so two connections
// could both redeem a max_uses = 1 coupon.
//
// These two ordinals give the caps the same shape the serial engine gives a number (SERIAL_ENGINE §4): the owner
// row (`coupons`) is locked FOR UPDATE and decides, and a UNIQUE index is the backstop. `coupon_use_seq` is the
// 1-based ordinal of this redemption within the coupon, `patient_use_seq` within (coupon, patient). Because the
// ordinal is `count(*) + 1` and only inserted when it is <= the cap, unique ordinals bound the number of rows by
// the cap even if the lock were lost: every accepted row has seq <= cap and no two rows share a seq.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->integer('coupon_use_seq')->nullable();
            $table->integer('patient_use_seq')->nullable();
        });

        // Backfill existing rows in id order — the order they were redeemed in.
        DB::statement(<<<'SQL'
            UPDATE coupon_redemptions r
            SET coupon_use_seq = o.coupon_seq, patient_use_seq = o.patient_seq
            FROM (
                SELECT id,
                       row_number() OVER (PARTITION BY coupon_id ORDER BY id) AS coupon_seq,
                       row_number() OVER (PARTITION BY coupon_id, patient_id ORDER BY id) AS patient_seq
                FROM coupon_redemptions
            ) o
            WHERE o.id = r.id
        SQL);

        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->integer('coupon_use_seq')->nullable(false)->change();
            $table->integer('patient_use_seq')->nullable(false)->change();

            $table->unique(['coupon_id', 'coupon_use_seq'], 'coupon_redemptions_coupon_use_seq_uniq');
            $table->unique(['coupon_id', 'patient_id', 'patient_use_seq'], 'coupon_redemptions_patient_use_seq_uniq');
        });

        DB::statement('ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemptions_coupon_use_seq_check CHECK (coupon_use_seq >= 1)');
        DB::statement('ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemptions_patient_use_seq_check CHECK (patient_use_seq >= 1)');

        // `uses_count` is the cached mirror of coupon_use_seq; keep any pre-existing drift from surviving the fix.
        DB::statement(<<<'SQL'
            UPDATE coupons c
            SET uses_count = COALESCE((SELECT count(*) FROM coupon_redemptions r WHERE r.coupon_id = c.id), 0)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE coupon_redemptions DROP CONSTRAINT IF EXISTS coupon_redemptions_coupon_use_seq_check');
        DB::statement('ALTER TABLE coupon_redemptions DROP CONSTRAINT IF EXISTS coupon_redemptions_patient_use_seq_check');

        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->dropUnique('coupon_redemptions_coupon_use_seq_uniq');
            $table->dropUnique('coupon_redemptions_patient_use_seq_uniq');
            $table->dropColumn(['coupon_use_seq', 'patient_use_seq']);
        });
    }
};
