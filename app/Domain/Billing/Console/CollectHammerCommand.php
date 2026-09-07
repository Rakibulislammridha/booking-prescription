<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Actions\RecordCashPayment;
use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Data\PaymentRequest;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Shared\Actor;
use App\Domain\Shared\Exceptions\DomainException;
use App\Models\Central\Tenant;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Invoice;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * billing:hammer — the concurrency worker of CONVENTIONS §6.5 for money.
 *
 * Two shapes, both about the same guarantee:
 *   --receipt=…      every worker replays the SAME receipt (an offline event redelivered by N devices at once);
 *                    exactly one `payments` row must exist afterwards.
 *   --amount=…       every worker collects a slice of the bill concurrently; the sum of the accepted payments
 *                    must never exceed the invoice total, because `paid_paisa <= total_paisa` is a CHECK and the
 *                    invoice row is locked for the duration of each write.
 *
 * Dev/testing only; prints one JSON line per attempt so ProcessPool can count outcomes.
 */
final class CollectHammerCommand extends Command
{
    protected $signature = 'billing:hammer {--tenant= : Tenant id} {--appointment= : appointments.id} {--invoice= : invoices.id} {--receipt= : Replay this receipt number every time} {--amount=0 : Amount in paisa per attempt} {--count=10} {--start-at= : Unix timestamp (float) so parallel workers start together}';

    protected $description = 'Hammer the billing collect path from one process (used by tests/Concurrency via ProcessPool)';

    public function handle(RecordCashPayment $cash, RecordPayment $payments): int
    {
        if (app()->isProduction()) {
            $this->components->error('billing:hammer refuses to run in production.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->findOrFail((int) $this->option('tenant'));
        $count = max(1, (int) $this->option('count'));
        $receipt = $this->option('receipt');
        $amount = (int) $this->option('amount');
        $worker = (string) (getenv('WORKER_INDEX') ?: '0');

        $this->waitForStart();

        $failures = Tenancy::run($tenant, function () use ($cash, $payments, $count, $receipt, $amount, $worker): int {
            $failed = 0;

            for ($i = 0; $i < $count; $i++) {
                usleep(random_int(0, 5000));

                try {
                    if (is_string($receipt) && $receipt !== '') {
                        /** @var Appointment $appointment */
                        $appointment = Appointment::query()->findOrFail((int) $this->option('appointment'));
                        $result = $cash->handle($appointment, $amount, $receipt, Actor::system());
                    } else {
                        /** @var Invoice $invoice */
                        $invoice = Invoice::query()->findOrFail((int) $this->option('invoice'));
                        $result = $payments->handle($invoice, new PaymentRequest(
                            method: PaymentMethod::Cash,
                            amountPaisa: $amount,
                            idempotencyKey: "hammer:{$worker}:{$i}",
                        ), Actor::system());
                    }

                    $this->line(json_encode(['ok' => true, 'duplicate' => $result->duplicate, 'amount' => $result->payment->amount_paisa], JSON_THROW_ON_ERROR));
                } catch (DomainException $e) {
                    // A refusal is a correct outcome here — it is the engine declining to over-collect.
                    $this->line(json_encode(['ok' => false, 'code' => $e->code()], JSON_THROW_ON_ERROR));
                } catch (Throwable $e) {
                    $failed++;
                    $this->line(json_encode(['ok' => false, 'code' => 'unexpected', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
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
