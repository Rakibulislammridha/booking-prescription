<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\User;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\TestCase;

/**
 * POST /panel/billing/invoices — the desk's "make a bill" button, and the one invoice ability that cannot carry a
 * DoctorScope conjunct in the policy: `create` is class-level (`billing.payments.collect`, which a compounder
 * holds) and the row the request acts on arrives in the BODY as a public id. So the scope has to be asked of the
 * resolved appointment, and until it was, any holder of the permission could raise AND issue a bill against any
 * booking in the tenant and be redirected onto the invoice screen with it.
 */
final class InvoiceAppointmentScopeTest extends TestCase
{
    use BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /** A compounder on `$mine` — the role already holds `billing.payments.collect`, which is the point. */
    private function actingAsCompounder(Doctor ...$mine): User
    {
        $user = $this->actingAsStaff(Role::Compounder);
        $user->assignedDoctors()->sync(array_map(fn (Doctor $d) => $d->id, $mine));

        return $user;
    }

    public function test_a_bill_may_only_be_raised_for_a_booking_inside_the_callers_scope(): void
    {
        $mine = Doctor::factory()->complete()->create();
        $mySession = $this->openSession(doctor: $mine);
        $theirSession = $this->openSession();

        // Booked by the admin, not by the caller under test: BillingFixtures bills the signed-in user as the actor.
        $this->actingAsStaff(Role::HospitalAdmin);
        $ours = $this->book($mySession, '01710000601', 'My Patient')->appointment;
        $theirs = $this->book($theirSession, '01710000602', 'Their Patient')->appointment;

        $this->actingAsCompounder($mine);
        $url = route('panel.billing.invoices.store', [], false);

        // The booking listener already drafted both bills, so the tell is ISSUE: `store` issues what it opens, and
        // a refused call must leave the colleague's bill exactly as it found it.
        $this->post($url, ['appointment' => $theirs->public_id])->assertStatus(403);
        $this->assertSame(InvoiceStatus::Draft, $this->liveInvoiceFor($theirs->id)->status);

        $this->post($url, ['appointment' => $ours->public_id])
            ->assertRedirect(route('panel.billing.invoices.show', ['invoice' => $this->liveInvoiceFor($ours->id)->public_id], false));
        $this->assertSame(InvoiceStatus::Issued, $this->liveInvoiceFor($ours->id)->status);
    }

    private function liveInvoiceFor(int $appointmentId): Invoice
    {
        return Invoice::query()->where('appointment_id', $appointmentId)->live()->firstOrFail();
    }

    /** The class-level door is untouched for everyone else: an unrestricted desk bills any booking as before. */
    public function test_an_unrestricted_desk_still_raises_a_bill_for_any_booking(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $appointment = $this->book($this->openSession(), '01710000603', 'Anyone')->appointment;

        $this->post(route('panel.billing.invoices.store', [], false), ['appointment' => $appointment->public_id])
            ->assertRedirectContains('/panel/billing/invoices/');
    }
}
