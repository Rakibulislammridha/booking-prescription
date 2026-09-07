<?php

declare(strict_types=1);

namespace App\Domain\Billing\Policies;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\User;

/**
 * Who may see and change a bill (ARCHITECTURE §6.2). Receptionists collect and view; accountants additionally
 * refund, approve discounts and read the reports; a doctor sees only his own patients' invoices; the hospital
 * admin holds everything.
 */
final class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->can(Permission::BillingInvoicesView->value) || $user->hasRole(Role::Doctor->value));
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if (! $user->is_active) {
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
        return $user->is_active && $invoice->status === InvoiceStatus::Draft && $user->can(Permission::BillingPaymentsCollect->value);
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $this->update($user, $invoice);
    }

    public function collect(User $user, Invoice $invoice): bool
    {
        return $user->is_active && $user->can(Permission::BillingPaymentsCollect->value);
    }

    public function discount(User $user, Invoice $invoice): bool
    {
        return $user->is_active && $user->can(Permission::BillingPaymentsCollect->value);
    }

    public function refund(User $user, Invoice $invoice): bool
    {
        return $user->is_active && $user->can(Permission::BillingRefundsIssue->value);
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $user->is_active && ($user->hasRole(Role::HospitalAdmin->value) || $user->can(Permission::BillingRefundsIssue->value));
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
