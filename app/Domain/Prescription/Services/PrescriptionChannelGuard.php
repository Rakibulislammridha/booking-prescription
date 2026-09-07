<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Models\Tenant\Prescription;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Authorises `tenant.{tenantPublicId}.prescription.{prescriptionPublicId}` (REALTIME.md §2, CONVENTIONS §15) — the
 * private channel the writer tab listens on for `pdf.ready` (PRESCRIPTION.md §7.5).
 *
 * Same shape as App\Domain\Queue\ChannelGuards: assert the `{tenant}` segment is the current tenant (the tenancy
 * middleware has already run on /broadcasting/auth), then apply the policy that governs reading the prescription
 * itself. A doctor must not learn that a colleague's prescription finished rendering.
 */
final class PrescriptionChannelGuard
{
    public function view(?Authenticatable $auth, string $tenant, string $prescription): bool
    {
        if (! $auth instanceof User || ! $auth->is_active || Tenancy::current()?->public_id !== $tenant) {
            return false;
        }

        $row = Prescription::query()->where('public_id', $prescription)->first();

        return $row !== null && $auth->can('view', $row);
    }
}
