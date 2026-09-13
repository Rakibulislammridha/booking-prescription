<?php

declare(strict_types=1);

namespace App\Domain\Billing\Policies;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\DoctorScope;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\User;

/**
 * Who may see and change a bill (ARCHITECTURE §6.2). Receptionists collect and view; accountants additionally
 * refund, approve discounts and read the reports; a doctor sees only his own patients' invoices; the hospital
 * admin holds everything.
 *
 * A compounder holds the view and collect permissions so that the fee they just took stays legible at the desk —
 * but clinic-wide those two permissions are every bill in the building, so each row-level ability also asks
 * DoctorScope (`invoices.doctor_id`). A bill that names no doctor — raised at the counter — is refused to a
 * restricted user rather than guessed at; the list at Billing/Invoices applies the same filter as a WHERE.
 *
 * `refund` and `void` ask too, though `billing.refunds.issue` is not a compounder permission. The rule is about the
 * ACCOUNT, not the role: one user granted compounder AND accountant reads a doctor-scoped board and ledger and could
 * still refund or void any doctor's bill from the accountant half, and the response hands the whole invoice back.
 * Holding the compounder role always restricts, so there is no ability here without the conjunct.
 */
final class InvoicePolicy
{
    public function __construct(private readonly DoctorScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->can(Permission::BillingInvoicesView->value) || $user->hasRole(Role::Doctor->value));
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if (! $user->is_active || ! $this->scope->allows($user, $invoice->doctor_id)) {
            return false;
        }

        if ($user->can(Permission::BillingInvoicesView->value)) {
            return true;
        }

        // A doctor without the billing permission sees the bills of his own consultations only.
        return $user->hasRole(Role::Doctor->value) && $invoice->doctor_id !== null && $invoice->doctor_id === $user->doctor?->id;
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->can(Permission::BillingPaymentsCollect->value);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->is_active && $invoice->status === InvoiceStatus::Draft && $user->can(Permission::BillingPaymentsCollect->value)
            && $this->scope->allows($user, $invoice->doctor_id);
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $this->update($user, $invoice);
    }

    public function collect(User $user, Invoice $invoice): bool
    {
        return $user->is_active && $user->can(Permission::BillingPaymentsCollect->value)
            && $this->scope->allows($user, $invoice->doctor_id);
    }

    public function discount(User $user, Invoice $invoice): bool
    {
        return $user->is_active && $user->can(Permission::BillingPaymentsCollect->value)
            && $this->scope->allows($user, $invoice->doctor_id);
    }

    public function refund(User $user, Invoice $invoice): bool
    {
        return $user->is_active && $user->can(Permission::BillingRefundsIssue->value)
            && $this->scope->allows($user, $invoice->doctor_id);
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $user->is_active
            && ($user->hasRole(Role::HospitalAdmin->value) || $user->can(Permission::BillingRefundsIssue->value))
            && $this->scope->allows($user, $invoice->doctor_id);
    }

    public function print(User $user, Invoice $invoice): bool
    {
        return $this->view($user, $invoice);
    }

    public function reports(User $user): bool
    {
        return $user->is_active && $user->can(Permission::BillingReportsView->value);
    }
}
