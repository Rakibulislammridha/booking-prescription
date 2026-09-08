<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Actions\ApplyCoupon;
use App\Domain\Shared\Actor;
use App\Domain\Shared\Exceptions\DomainException;
use App\Models\Central\Tenant;
use App\Models\Tenant\Coupon;
use App\Models\Tenant\Invoice;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * billing:coupon-hammer — the concurrency worker of CONVENTIONS §6.5 for coupon caps.
 *
 * Every worker races to redeem the SAME coupon, each attempt on a different invoice (the per-invoice unique index
 * would otherwise be doing all the work). What must hold afterwards:
 *   `max_uses = 1`               → exactly one `coupon_redemptions` row exists, `uses_count = 1`;
 *   `max_uses_per_patient = 1`   → exactly one row for that patient, however many invoices they hold.
 *
 * `--skip-coupon-lock` drops the FOR UPDATE on the `coupons` row so a test can prove that the unique ordinals
 * (`coupon_redemptions_coupon_use_seq_uniq`, `…_patient_use_seq_uniq`) hold the caps on their own — the same
 * proof `serials:hammer --skip-owner-lock` gives for `serials_session_number_uniq`. Honoured only under
 * APP_ENV=testing (`ApplyCoupon::lockCoupon()`).
 *
 * Dev/testing only; prints one JSON line per attempt so ProcessPool can count outcomes.
 */
final class CouponHammerCommand extends Command
{
    protected $signature = 'billing:coupon-hammer {--tenant= : Tenant id} {--coupon= : coupons.id} {--invoices= : Comma-separated invoices.id to spread attempts over} {--count=10} {--skip-coupon-lock : Drop the coupons FOR UPDATE (testing only)} {--start-at= : Unix timestamp (float) so parallel workers start together}';

    protected $description = 'Hammer the coupon redemption path from one process (used by tests/Concurrency via ProcessPool)';

    public function handle(ApplyCoupon $apply): int
    {
        if (app()->isProduction()) {
            $this->components->error('billing:coupon-hammer refuses to run in production.');

            return self::FAILURE;
        }

        if ((bool) $this->option('skip-coupon-lock')) {
            config(['billing.testing_skip_coupon_lock' => true]);
        }

        $tenant = Tenant::query()->findOrFail((int) $this->option('tenant'));
        $count = max(1, (int) $this->option('count'));
        $worker = (int) (getenv('WORKER_INDEX') ?: '0');

        /** @var array<int, int> $invoiceIds */
        $invoiceIds = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('invoices')))));

        if ($invoiceIds === []) {
            $this->components->error('--invoices is required');

            return self::FAILURE;
        }

        $this->waitForStart();

        $failures = Tenancy::run($tenant, function () use ($apply, $count, $invoiceIds, $worker): int {
            $failed = 0;
            /** @var Coupon $coupon */
            $coupon = Coupon::query()->findOrFail((int) $this->option('coupon'));

            for ($i = 0; $i < $count; $i++) {
                usleep(random_int(0, 5000));

                // Rotate by worker index so every worker starts on a different invoice and they collide mid-run.
                $invoiceId = $invoiceIds[($worker + $i) % count($invoiceIds)];

                try {
                    /** @var Invoice $invoice */
                    $invoice = Invoice::query()->findOrFail($invoiceId);
                    $redemption = $apply->handle($invoice, $coupon, Actor::system());

                    $this->line(json_encode(['ok' => true, 'invoice' => $invoiceId, 'seq' => $redemption->coupon_use_seq], JSON_THROW_ON_ERROR));
                } catch (DomainException $e) {
                    // A refusal is the correct outcome here — it is the cap declining another redemption.
                    $this->line(json_encode(['ok' => false, 'invoice' => $invoiceId, 'code' => $e->code()], JSON_THROW_ON_ERROR));
                } catch (Throwable $e) {
                    $failed++;
                    $this->line(json_encode(['ok' => false, 'invoice' => $invoiceId, 'code' => 'unexpected', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
                }
            }

            return $failed;
        });

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function waitForStart(): void
    {
        $startAt = $this->option('start-at');

        if (! is_string($startAt) || $startAt === '') {
            return;
        }

        $wait = (float) $startAt - microtime(true);

        if ($wait > 0) {
            usleep((int) min(30_000_000, $wait * 1_000_000));
        }
    }
}
