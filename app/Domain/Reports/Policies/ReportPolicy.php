<?php

declare(strict_types=1);

namespace App\Domain\Reports\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Reports\Enums\ReportKind;
use App\Models\Tenant\User;

/**
 * Reports have no model of their own, so the abilities are registered as gates by `ReportsServiceProvider`
 * (`Gate::define('reports.view', [ReportPolicy::class, 'view'])`, …) and controllers call
 * `Gate::authorize('reports.open', $kind)`. Keeping them in a policy class rather than in closures means the
 * matrix is testable on its own and reads like every other module's policy.
 */
final class ReportPolicy
{
    /** The section itself: without this the nav entry is hidden and every route 403s. */
    public function view(User $user): bool
    {
        return $user->is_active && $user->can(Permission::ReportsView->value);
    }

    /** One specific report family — money and clinical detail are separately gated. */
    public function open(User $user, ReportKind $kind): bool
    {
        if (! $this->view($user)) {
            return false;
        }

        return match (true) {
            $kind->isFinancial() => $user->can(Permission::ReportsFinancialView->value),
            $kind->isClinical() => $user->can(Permission::ReportsClinicalView->value),
            default => true,
        };
    }

    /** Exporting is its own permission: reading a number on screen and walking out with the file differ. */
    public function export(User $user, ReportKind $kind): bool
    {
        return $this->open($user, $kind) && $user->can(Permission::ReportsExport->value);
    }
}
