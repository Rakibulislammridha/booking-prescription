<?php

declare(strict_types=1);

namespace Tests\Concurrency\Billing;

use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Coupon;
use App\Models\Tenant\CouponRedemption;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Patient;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\Support\ProcessPool;
use Tests\Support\ProcessPoolResult;
use Tests\TestCase;

/**
 * CONVENTIONS §6.5 for the coupon caps. `coupon_redemptions_invoice_id_uniq` only ever stopped a double submit on
 * ONE invoice; `max_uses` and `max_uses_per_patient` are decided by counting rows, which is meaningless unless the
 * counting transaction holds the owner row. These tests race real processes across DIFFERENT invoices, where the
 * per-invoice index cannot help, and assert the cap and the cached `uses_count` are both exactly right.
 *
 * The third test drops the FOR UPDATE (`--skip-coupon-lock`, honoured only under APP_ENV=testing) and proves the
 * unique ordinals hold the cap on their own — the backstop, exactly as SERIAL_ENGINE §18.1 does for numbers.
 *
 * Row counts alone cannot tell the lock from the backstop: both leave exactly one redemption. What tells them
 * apart is HOW every refusal was produced (the hammer's `via`): under the lock every over-cap attempt is refused
 * by CouponValidator with the clean `exhausted` outcome and the unique-violation translation path is never
 * exercised; without the lock that path is exactly what saves the cap, so it must have been hit.
 */
#[Group('concurrency')]
final class ApplyCouponConcurrencyTest extends TestCase
{
    use BillingFixtures;

    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::Receptionist);
    }

    protected function tearDown(): void
    {
        $this->truncateTenantTables('a', [
            'refunds', 'payments', 'coupon_redemptions', 'discounts', 'invoice_items', 'invoices', 'cash_shifts',
            'doctor_revenue_shares', 'coupons', 'appointments', 'serial_events', 'serials', 'serial_pools',
            'serial_blocks', 'session_instances', 'patients', 'audit_logs',
        ]);
        parent::tearDown();
    }

    public function test_parallel_desks_can_redeem_a_single_use_coupon_exactly_once(): void
    {
        $coupon = Coupon::factory()->create([
            'code' => 'ONLYONE', 'type' => DiscountType::Fixed, 'value' => '50.00', 'max_uses' => 1, 'max_uses_per_patient' => 1,
        ]);

        $invoiceIds = $this->invoicesForDistinctPatients(8);

        $results = ProcessPool::run(workers: 8, command: [
            'php', 'artisan', 'billing:coupon-hammer',
            '--tenant=9001',
            "--coupon={$coupon->id}",
            '--invoices='.implode(',', $invoiceIds),
            '--count=6',
            '--start-at='.(microtime(true) + 2.0),
        ], timeoutSeconds: 180);

        $redemptions = CouponRedemption::query()->where('coupon_id', $coupon->id)->get();
        $usesCount = (int) Coupon::query()->whereKey($coupon->id)->value('uses_count');

        fwrite(STDERR, sprintf("\n[concurrency] max_uses=1: 8 processes x 6 attempts over 8 invoices = 48 tries, redemptions = %d, uses_count = %d, worker failures %d\n", $redemptions->count(), $usesCount, $results->failed()));

        $this->assertCount(1, $redemptions, '48 concurrent attempts at a max_uses = 1 coupon must leave exactly one redemption');
        $this->assertSame([1], $redemptions->pluck('coupon_use_seq')->all());
        $this->assertSame(1, (int) Coupon::query()->whereKey($coupon->id)->value('uses_count'), 'the cached counter is the row count, never 2');
        $this->assertSame(0, $results->failed(), 'no worker crashed');

        // The lock's own signature: 47 refusals, every one decided by the validator under the coupon lock, and
        // every over-cap one with the clean "used up" reason. Remove the FOR UPDATE and this is what changes.
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['ok']);
        $this->assertSame(47, $outcomes['refused']);
        $this->assertSame(0, $outcomes['backstop'], 'with the owner lock held, the unique-violation translation path must never be exercised');
        $this->assertSame(47, $outcomes['validator']);
        $this->assertGreaterThan(0, $outcomes['exhausted'], 'the cap itself was what refused the later attempts');
        $this->assertSame($outcomes['exhausted'] + $outcomes['already_applied'], $outcomes['refused'], 'nothing but the cap and the per-bill rule ever said no');
    }

    public function test_parallel_desks_can_redeem_a_once_per_patient_coupon_once_for_that_patient(): void
    {
        $coupon = Coupon::factory()->create([
            'code' => 'ONEPERPATIENT', 'type' => DiscountType::Fixed, 'value' => '50.00', 'max_uses' => null, 'max_uses_per_patient' => 1,
        ]);

        // Eight bills for ONE patient: nothing but the per-patient cap can stop the second redemption.
        $invoiceIds = $this->invoicesForOnePatient(8);

        $results = ProcessPool::run(workers: 8, command: [
            'php', 'artisan', 'billing:coupon-hammer',
            '--tenant=9001',
            "--coupon={$coupon->id}",
            '--invoices='.implode(',', $invoiceIds),
            '--count=6',
            '--start-at='.(microtime(true) + 2.0),
        ], timeoutSeconds: 180);

        $redemptions = CouponRedemption::query()->where('coupon_id', $coupon->id)->get();
        $usesCount = (int) Coupon::query()->whereKey($coupon->id)->value('uses_count');

        fwrite(STDERR, sprintf("\n[concurrency] max_uses_per_patient=1: 8 processes x 6 attempts over 8 bills of ONE patient = 48 tries, redemptions = %d, uses_count = %d, worker failures %d\n", $redemptions->count(), $usesCount, $results->failed()));

        $this->assertCount(1, $redemptions, 'one patient, max_uses_per_patient = 1, eight bills: exactly one redemption');
        $this->assertSame(1, (int) $redemptions->first()?->patient_use_seq);
        $this->assertSame(1, Patient::query()->count(), 'the fixture really did put every bill on one patient');
        $this->assertSame(1, (int) Coupon::query()->whereKey($coupon->id)->value('uses_count'));
        $this->assertSame(0, $results->failed(), 'no worker crashed');
    }

    public function test_the_unique_ordinals_hold_the_cap_even_without_the_owner_lock(): void
    {
        $coupon = Coupon::factory()->create([
            'code' => 'NOLOCK', 'type' => DiscountType::Fixed, 'value' => '50.00', 'max_uses' => 3, 'max_uses_per_patient' => 1,
        ]);

        $invoiceIds = $this->invoicesForDistinctPatients(8);

        $results = ProcessPool::run(workers: 8, command: [
            'php', 'artisan', 'billing:coupon-hammer',
            '--tenant=9001',
            "--coupon={$coupon->id}",
            '--invoices='.implode(',', $invoiceIds),
            '--count=6',
            '--skip-coupon-lock',
            '--start-at='.(microtime(true) + 2.0),
        ], timeoutSeconds: 180);

        $seqs = CouponRedemption::query()->where('coupon_id', $coupon->id)->orderBy('coupon_use_seq')->pluck('coupon_use_seq')->all();

        fwrite(STDERR, sprintf("\n[concurrency] no-lock proof: max_uses=3, 8 x 6 attempts, rows = %d, ordinals = [%s], worker failures %d\n", count($seqs), implode(',', $seqs), $results->failed()));

        // Without the lock a worker may lose a race and be refused, so fewer than 3 is legal — more is not.
        $this->assertLessThanOrEqual(3, count($seqs), 'coupon_redemptions_coupon_use_seq_uniq must cap the rows at max_uses with no lock at all');
        $this->assertGreaterThan(0, count($seqs));
        $this->assertSame(array_values(array_unique($seqs)), $seqs, 'every ordinal is distinct');
        $this->assertSame(range(1, count($seqs)), $seqs, 'and the ordinals are dense from 1');

        // And the proof that this run really was lockless: the backstop had to speak. Under the lock it never does.
        $outcomes = $this->outcomes($results);
        $this->assertGreaterThan(0, $outcomes['backstop'], 'with no owner lock, stale counts collide on the unique ordinals and the translation path is what refuses them');
        $this->assertSame(0, $results->failed(), 'a backstop refusal is a clean 422, never a crash');
    }

    /**
     * Tally the hammer's JSON lines: how many attempts succeeded, and how each refusal was produced.
     *
     * @return array{ok: int, refused: int, validator: int, backstop: int, exhausted: int, already_applied: int}
     */
    private function outcomes(ProcessPoolResult $results): array
    {
        $tally = ['ok' => 0, 'refused' => 0, 'validator' => 0, 'backstop' => 0, 'exhausted' => 0, 'already_applied' => 0];

        foreach ($results->lines() as $line) {
            /** @var array{ok: bool, code?: string, reason?: string|null, via?: string} $row */
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            if ($row['ok']) {
                $tally['ok']++;

                continue;
            }

            $tally['refused']++;
            $tally[$row['via'] ?? 'validator'] = ($tally[$row['via'] ?? 'validator'] ?? 0) + 1;

            if (($row['code'] ?? '') === 'billing.coupon_already_applied') {
                $tally['already_applied']++;
            } elseif (($row['reason'] ?? null) === __('billing.coupon.reason.exhausted')) {
                $tally['exhausted']++;
            }
        }

        return $tally;
    }

    /**
     * One issued invoice per patient, all on one session; the coupon has nothing but its own caps to stop it.
     *
     * @return array<int, int>
     */
    private function invoicesForDistinctPatients(int $n): array
    {
        $session = $this->openSession(counter: $n + 2, online: 2, buffer: 2);
        $ids = [];

        for ($i = 0; $i < $n; $i++) {
            $booked = $this->book($session, mobile: '0171000'.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT), name: 'Racer '.$i);
            $ids[] = $this->issuedInvoiceFor($booked->appointment)->id;
        }

        return $ids;
    }

    /**
     * One patient, `$n` issued bills. A patient may hold only one live appointment per session, so each bill is a
     * different doctor's session — which is exactly the shape the per-patient cap exists for.
     *
     * @return array<int, int>
     */
    private function invoicesForOnePatient(int $n): array
    {
        $ids = [];

        for ($i = 0; $i < $n; $i++) {
            $booked = $this->book($this->openSession(counter: 3, online: 2, buffer: 2), mobile: '01710009999', name: 'Repeat Visitor');
            $ids[] = $this->issuedInvoiceFor($booked->appointment)->id;
        }

        return $ids;
    }
}
