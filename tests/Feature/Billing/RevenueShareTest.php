<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Actions\IssueRefund;
use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Data\PaymentRequest;
use App\Domain\Billing\Data\RefundRequest;
use App\Domain\Billing\Enums\InvoiceItemType;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\RefundReason;
use App\Domain\Billing\Enums\RevenueShareItemType;
use App\Domain\Billing\Queries\CollectionReportQuery;
use App\Domain\Billing\Queries\RevenueShareReportQuery;
use App\Domain\Billing\Services\RevenueShareResolver;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\DoctorRevenueShare;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\TestCase;

/** The commission split is resolved once, frozen on the invoice item, and never rewritten by a later rule change. */
final class RevenueShareTest extends TestCase
{
    use BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::Accountant);
    }

    public function test_the_most_specific_rule_wins_and_ties_go_to_the_newest(): void
    {
        $session = $this->openSession();
        $doctor = $this->doctorOf($session);
        $branch = $this->mainBranch();
        $resolver = app(RevenueShareResolver::class);

        $catchAll = DoctorRevenueShare::factory()->percentage(50)->create(['doctor_id' => $doctor->id, 'branch_id' => null, 'item_type' => RevenueShareItemType::All]);
        $this->assertSame($catchAll->id, $resolver->resolve($doctor->id, $branch->id, InvoiceItemType::Consultation, Clock::today())?->id);

        // An exact item type beats the catch-all.
        $resolver->forget();
        $exact = DoctorRevenueShare::factory()->percentage(60)->create(['doctor_id' => $doctor->id, 'branch_id' => null, 'item_type' => RevenueShareItemType::Consultation]);
        $this->assertSame($exact->id, $resolver->resolve($doctor->id, $branch->id, InvoiceItemType::Consultation, Clock::today())?->id);

        // A branch-specific rule beats an all-branch rule of the same specificity.
        $resolver->forget();
        $branchRule = DoctorRevenueShare::factory()->percentage(70)->create(['doctor_id' => $doctor->id, 'branch_id' => $branch->id, 'item_type' => RevenueShareItemType::Consultation]);
        $this->assertSame($branchRule->id, $resolver->resolve($doctor->id, $branch->id, InvoiceItemType::Consultation, Clock::today())?->id);

        // A rule that is not yet effective is not applied.
        $resolver->forget();
        DoctorRevenueShare::factory()->percentage(90)->create([
            'doctor_id' => $doctor->id, 'branch_id' => $branch->id, 'item_type' => RevenueShareItemType::Consultation,
            'effective_from' => Clock::today()->addDays(30)->toDateString(),
        ]);
        $this->assertSame($branchRule->id, $resolver->resolve($doctor->id, $branch->id, InvoiceItemType::Consultation, Clock::today())?->id);
    }

    public function test_the_split_is_frozen_at_issue_and_a_later_rule_change_never_rewrites_it(): void
    {
        $session = $this->openSession();
        $doctor = $this->doctorOf($session);
        $rule = DoctorRevenueShare::factory()->percentage(60)->create(['doctor_id' => $doctor->id]);

        $booked = $this->book($session);
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $item = $invoice->items()->firstOrFail();

        $this->assertSame($rule->id, $item->doctor_revenue_share_id);
        $this->assertSame(48000, $item->doctor_share_paisa, '60% of 800.00');
        $this->assertSame(32000, $item->clinic_share_paisa);
        $this->assertSame($item->line_total_paisa, $item->doctor_share_paisa + $item->clinic_share_paisa, 'the split is exhaustive');

        // The clinic renegotiates: the rule becomes 30%.
        $rule->forceFill(['share_value' => '30.00'])->save();
        app(RevenueShareResolver::class)->forget();

        $this->assertSame(48000, $item->refresh()->doctor_share_paisa, 'history is untouched');

        // A new booking uses the new rule.
        $next = SessionInstance::factory()->openToday()->quotas(10, 10, 5)
            ->on(Clock::today()->addDay(), 'B')
            ->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id]);
        $second = $this->book($next, mobile: '01710000009', name: 'Karim Mia');
        $secondItem = $this->issuedInvoiceFor($second->appointment)->items()->firstOrFail();
        $this->assertSame(24000, $secondItem->doctor_share_paisa, '30% of 800.00 for the new bill only');
    }

    public function test_a_flat_share_and_no_rule_at_all_are_both_exhaustive(): void
    {
        $session = $this->openSession();
        $doctor = $this->doctorOf($session);
        DoctorRevenueShare::factory()->fixed(30000)->create(['doctor_id' => $doctor->id]);

        $booked = $this->book($session);
        $item = $this->issuedInvoiceFor($booked->appointment)->items()->firstOrFail();

        $this->assertSame(30000, $item->doctor_share_paisa);
        $this->assertSame(50000, $item->clinic_share_paisa);

        // A doctor with no rule: the clinic keeps everything, and the columns still add up.
        $other = $this->book($this->openSession(), mobile: '01710000011', name: 'No Rule');
        $otherItem = $this->issuedInvoiceFor($other->appointment)->items()->firstOrFail();
        $this->assertSame(0, $otherItem->doctor_share_paisa);
        $this->assertSame($otherItem->line_total_paisa, $otherItem->clinic_share_paisa);
    }

    public function test_the_commission_report_reads_only_the_frozen_amounts(): void
    {
        $session = $this->openSession();
        $doctor = $this->doctorOf($session);
        DoctorRevenueShare::factory()->percentage(60)->create(['doctor_id' => $doctor->id]);

        $booked = $this->book($session);
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        app(RecordPayment::class)->handle($invoice, new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 40000, idempotencyKey: 'share-1',
        ), $this->staffActor());

        $report = app(RevenueShareReportQuery::class)->summary(Clock::today(), Clock::today());

        $this->assertSame(80000, $report['totals']['billed_paisa']);
        $this->assertSame(48000, $report['totals']['doctor_share_paisa']);
        $this->assertSame(32000, $report['totals']['clinic_share_paisa']);
        $this->assertSame(40000, $report['totals']['collected_paisa'], 'half the bill is paid, so half the line counts as collected');

        $row = $report['rows'][0];
        $this->assertSame($doctor->id, $row['doctor_id']);
        $this->assertSame('consultation', $row['item_type']);
    }

    public function test_the_collection_report_nets_refunds_out_of_the_gross(): void
    {
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $paid = app(RecordPayment::class)->handle($invoice, new PaymentRequest(
            method: PaymentMethod::Cash, amountPaisa: 80000, idempotencyKey: 'collect-1',
        ), $this->staffActor());

        app(IssueRefund::class)->handle($paid->payment, new RefundRequest(
            reasonCode: RefundReason::Goodwill, amountPaisa: 20000,
        ), $this->staffActor());

        $report = app(CollectionReportQuery::class)->summary(Clock::today(), Clock::today());

        $this->assertSame(80000, $report['totals']['gross_paisa']);
        $this->assertSame(20000, $report['totals']['refunds_paisa']);
        $this->assertSame(60000, $report['totals']['net_paisa']);
        $this->assertSame(1, $report['totals']['payment_count']);
        $this->assertSame('cash', $report['by_method'][0]['key']);
        $this->assertSame(60000, $report['by_method'][0]['net_paisa']);
    }

    public function test_the_reports_screen_and_its_csv_export_are_permission_guarded_and_audited(): void
    {
        $accountant = $this->actingAsStaff(Role::Accountant);
        $this->get(route('panel.billing.reports.index', [], false))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Billing/Reports')->has('collection')->has('commission'));

        $csv = $this->get(route('panel.billing.reports.export', ['kind' => 'commission'], false))->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $this->assertAudited(AuditAction::Export, $accountant, ['report' => 'commission']);

        // A receptionist has billing.payments.collect but not billing.reports.view.
        $this->actingAsStaff(Role::Receptionist);
        $this->get(route('panel.billing.reports.index', [], false))->assertForbidden();
    }
}
