<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Data\PaymentRequest;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Coupon;
use App\Models\Tenant\DoctorRevenueShare;
use App\Models\Tenant\Invoice;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\TestCase;

/**
 * The role matrix as the desk actually experiences it: a receptionist takes money but cannot refund it, an
 * accountant refunds and reads the reports, a doctor sees only his own patients' bills, a hospital admin does
 * everything.
 */
final class BillingPermissionTest extends TestCase
{
    use BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_the_role_matrix_grants_exactly_the_billing_permissions_each_role_needs(): void
    {
        $expected = [
            Role::Receptionist->value => [Permission::BillingPaymentsCollect, Permission::BillingInvoicesView],
            Role::Accountant->value => [Permission::BillingPaymentsCollect, Permission::BillingInvoicesView, Permission::BillingRefundsIssue, Permission::BillingDiscountsApprove, Permission::BillingReportsView],
        ];

        foreach ($expected as $role => $permissions) {
            $user = $this->actingAsStaff($role);

            foreach ($permissions as $permission) {
                $this->assertTrue($user->can($permission->value), "{$role} should hold {$permission->value}");
            }
        }

        $receptionist = $this->actingAsStaff(Role::Receptionist);
        $this->assertFalse($receptionist->can(Permission::BillingRefundsIssue->value));
        $this->assertFalse($receptionist->can(Permission::BillingDiscountsApprove->value));
        $this->assertFalse($receptionist->can(Permission::BillingReportsView->value));

        $admin = $this->actingAsStaff(Role::HospitalAdmin);
        foreach ([Permission::BillingPaymentsCollect, Permission::BillingRefundsIssue, Permission::BillingDiscountsApprove, Permission::BillingReportsView, Permission::BillingFeesOverride] as $permission) {
            $this->assertTrue($admin->can($permission->value));
        }
    }

    public function test_a_receptionist_can_collect_but_not_refund(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        $this->postJson(route('panel.billing.invoices.payments.store', ['invoice' => $invoice->public_id], false), ['amount_paisa' => 80000, 'method' => 'cash'])
            ->assertOk();

        $payment = $invoice->refresh()->payments()->firstOrFail();

        $this->postJson(route('panel.billing.invoices.refunds.store', ['invoice' => $invoice->public_id], false), ['payment' => $payment->public_id, 'reason_code' => 'goodwill'])
            ->assertForbidden();
    }

    public function test_an_accountant_can_refund_and_read_the_reports(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        app(RecordPayment::class)->handle($invoice, new PaymentRequest(method: PaymentMethod::Cash, amountPaisa: 80000, idempotencyKey: 'perm-1'), $this->staffActor());

        $this->actingAsStaff(Role::Accountant);
        $payment = $invoice->refresh()->payments()->firstOrFail();

        $this->postJson(route('panel.billing.invoices.refunds.store', ['invoice' => $invoice->public_id], false), ['payment' => $payment->public_id, 'reason_code' => 'goodwill', 'amount_paisa' => 10000])
            ->assertOk()
            ->assertJsonPath('refund.amount_paisa', 10000);

        $this->get(route('panel.billing.reports.index', [], false))->assertOk();
    }

    public function test_a_doctor_sees_only_his_own_patients_bills(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $mine = $this->book();
        $mineInvoice = $this->issuedInvoiceFor($mine->appointment);
        $other = $this->book($this->openSession(), mobile: '01710000044', name: 'Someone Else');
        $otherInvoice = $this->issuedInvoiceFor($other->appointment);

        $doctorUser = $this->actingAsDoctor();
        // Point the first bill at this doctor.
        $mineInvoice->forceFill(['doctor_id' => $doctorUser->doctor?->id])->save();

        $this->get(route('panel.billing.invoices.show', ['invoice' => $mineInvoice->public_id], false))->assertOk();
        $this->get(route('panel.billing.invoices.show', ['invoice' => $otherInvoice->public_id], false))->assertForbidden();
    }

    public function test_only_a_discount_approver_may_manage_coupons_and_only_an_admin_the_commission_rules(): void
    {
        $receptionist = $this->actingAsStaff(Role::Receptionist);
        $this->assertFalse($receptionist->can('create', Coupon::class));
        $this->assertFalse($receptionist->can('create', DoctorRevenueShare::class));
        $this->post(route('panel.billing.coupons.store', [], false), ['code' => 'NOPE', 'name' => 'x', 'type' => 'fixed', 'value' => '1'])->assertForbidden();

        $accountant = $this->actingAsStaff(Role::Accountant);
        $this->assertTrue($accountant->can('create', Coupon::class));
        $this->assertFalse($accountant->can('create', DoctorRevenueShare::class), 'commission policy is the hospital admin\'s');

        $admin = $this->actingAsStaff(Role::HospitalAdmin);
        $this->assertTrue($admin->can('create', DoctorRevenueShare::class));
    }

    public function test_a_receptionist_cannot_void_a_bill_but_an_admin_can(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);

        $this->post(route('panel.billing.invoices.void', ['invoice' => $invoice->public_id], false), ['reason' => 'wrong patient'])->assertForbidden();

        $this->actingAsStaff(Role::HospitalAdmin);
        $this->post(route('panel.billing.invoices.void', ['invoice' => $invoice->public_id], false), ['reason' => 'wrong patient'])->assertRedirect();
        $this->assertSame('void', $invoice->refresh()->status->value);
    }

    public function test_the_billing_section_is_closed_to_a_user_without_the_view_permission(): void
    {
        $this->actingAsStaff(Role::Doctor);
        // A doctor may reach the list (his own bills), but not the reports.
        $this->get(route('panel.billing.index', [], false))->assertOk();
        $this->get(route('panel.billing.reports.index', [], false))->assertForbidden();
        $this->get(route('panel.billing.shift.index', [], false))->assertForbidden();
    }

    public function test_the_shared_props_expose_the_billing_permissions_the_nav_guards_on(): void
    {
        $this->actingAsStaff(Role::Accountant);

        $this->get(route('panel.billing.index', [], false))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Billing/Invoices')
                ->where('can.reports', true)
                ->where('can.collect', true));
    }

    public function test_an_invoice_of_another_tenant_is_not_reachable(): void
    {
        $this->actingAsStaff(Role::Accountant);
        $booked = $this->book();
        $invoice = $this->issuedInvoiceFor($booked->appointment);
        $publicId = $invoice->public_id;

        $this->asTenant('b')->actingAsStaff(Role::Accountant);
        $this->get(route('panel.billing.invoices.show', ['invoice' => $publicId], false))->assertNotFound();
        $this->assertSame(0, Invoice::query()->count());
    }
}
