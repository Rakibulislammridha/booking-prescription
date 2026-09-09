<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Exceptions\CouponAlreadyApplied;
use App\Domain\Billing\Exceptions\CouponInvalid;
use App\Domain\Billing\Exceptions\InvoiceNotEditable;
use App\Domain\Billing\Services\CouponValidator;
use App\Domain\Billing\Services\InvoiceLedger;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Coupon;
use App\Models\Tenant\CouponRedemption;
use App\Models\Tenant\Invoice;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Redeem a coupon on one invoice (BRIEF §5.I), built the way the serial engine builds an allocation
 * (SERIAL_ENGINE §4): lock the OWNER row first, decide inside the lock, keep a unique index as the backstop.
 *
 * The owner of a redemption budget is the `coupons` row, so it is locked FOR UPDATE before anything else. Every
 * concurrent redemption of the same coupon is therefore strictly serialised, and the counts `CouponValidator`
 * reads for `max_uses` / `max_uses_per_patient` cannot go stale between the count and the insert. The invoice is
 * locked second (coupon → invoice is the only lock order this action ever takes).
 *
 * Three unique indexes back it up, each for a different guarantee:
 *   `coupon_redemptions_invoice_id_uniq`        — one coupon per bill (a double submit).
 *   `coupon_redemptions_coupon_use_seq_uniq`    — at most `max_uses` rows, because the ordinal written is
 *                                                 `count + 1` and is only inserted when it is within the cap.
 *   `coupon_redemptions_patient_use_seq_uniq`   — the same, per patient.
 * With the lock held those violations are unreachable; they are translated below rather than surfaced as 500s,
 * and the `--skip-coupon-lock` mode of `billing:coupon-hammer` exists to prove they really do hold the line.
 *
 * `uses_count` is written as the ordinal just taken, never as `uses_count + 1`: a cached counter that is assigned
 * rather than incremented cannot lose an update or drift from the row count.
 */
final class ApplyCoupon
{
    public function __construct(
        private readonly InvoiceLedger $ledger,
        private readonly CouponValidator $validator,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Invoice $invoice, Coupon $coupon, Actor $actor): CouponRedemption
    {
        try {
            return DB::transaction(function () use ($invoice, $coupon, $actor): CouponRedemption {
                // 1. Owner row first (invariant I-OWNER): this serialises every redemption of this coupon.
                $lockedCoupon = $this->lockCoupon($coupon);

                /** @var Invoice $locked */
                $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

                if (in_array($locked->status, [InvoiceStatus::Void, InvoiceStatus::Paid, InvoiceStatus::Refunded], true)) {
                    throw new InvoiceNotEditable(['status' => $locked->status->value]);
                }

                $locked->load('items', 'appointment');
                $reducible = max(0, $locked->subtotal_paisa - $locked->discount_paisa);

                // 2. Decide inside the lock; the ordinals come back so the insert can carry them.
                $ordinals = $this->validator->assertUsable($lockedCoupon, $locked, $reducible);
                $amount = $this->validator->amountFor($lockedCoupon, $reducible);

                $redemption = new CouponRedemption;
                $redemption->forceFill([
                    'coupon_id' => $lockedCoupon->id,
                    'invoice_id' => $locked->id,
                    'patient_id' => $locked->patient_id,
                    'amount_paisa' => $amount,
                    'coupon_use_seq' => $ordinals['coupon_use_seq'],
                    'patient_use_seq' => $ordinals['patient_use_seq'],
                ])->save();

                // Assigned, never `uses_count + 1`: the caller's own $coupon instance is deliberately left alone
                // (it would go dirty and could be written back stale) — refresh() reads the truth.
                $lockedCoupon->forceFill(['uses_count' => $ordinals['coupon_use_seq']])->save();

                $synced = $this->ledger->sync($locked->refresh());

                $this->audit->record(AuditAction::Update, $synced, null, [
                    'coupon_code' => $lockedCoupon->code,
                    'coupon_discount_paisa' => $amount,
                    'total_paisa' => $synced->total_paisa,
                ], ['actor_user_id' => $actor->userId, 'actor_source' => $actor->source, 'event' => 'coupon_applied']);

                return $redemption;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Unreachable while I-OWNER holds; the backstop speaking is still a refusal, never a 500. The database
            // error stays attached as `previous`, so a refusal made HERE is distinguishable from the validator's
            // (ApplyCouponConcurrencyTest asserts the lock makes this path go quiet).
            $constraint = self::violatedConstraint($e);

            if (str_contains($constraint, 'invoice_id')) {
                throw new CouponAlreadyApplied(previous: $e);
            }

            if (str_contains($constraint, 'patient_use_seq')) {
                throw new CouponInvalid(['reason' => __('billing.coupon.reason.per_patient')], $e);
            }

            if (str_contains($constraint, 'coupon_use_seq')) {
                throw new CouponInvalid(['reason' => __('billing.coupon.reason.exhausted')], $e);
            }

            throw $e;
        } catch (QueryException $e) {
            if (CouponRedemption::query()->where('invoice_id', $invoice->id)->exists()) {
                throw new CouponAlreadyApplied(previous: $e);
            }

            throw $e;
        }
    }

    /**
     * FOR UPDATE on the coupon. The lock is skipped only under `app()->environment('testing')` with
     * `billing.testing_skip_coupon_lock` set, so a concurrency test can prove the unique ordinals hold the caps
     * on their own (CONVENTIONS §6.5, the same shape as `serials.testing_skip_owner_lock`). No static switch.
     */
    private function lockCoupon(Coupon $coupon): Coupon
    {
        $query = Coupon::query()->whereKey($coupon->id);

        if (! (app()->environment('testing') && (bool) config('billing.testing_skip_coupon_lock', false))) {
            $query->lockForUpdate();
        }

        /** @var Coupon $locked */
        $locked = $query->firstOrFail();

        return $locked;
    }

    /** The constraint name out of a Postgres 23505 message (the SQL text also names every column, so match the constraint only). */
    private static function violatedConstraint(UniqueConstraintViolationException $e): string
    {
        return preg_match('/unique constraint "([^"]+)"/', $e->getMessage(), $m) === 1 ? $m[1] : '';
    }
}
