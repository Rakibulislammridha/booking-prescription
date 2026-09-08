<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\SuperAdmin;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * `public.audit_logs_central` (SCHEMA §2.13) — the super admin's side of the trail, the counterpart of
 * `App\Domain\Audit\AuditRecorder` inside a tenant.
 *
 * Append-only by design and by grant: there is no update or delete path here, and the actor is taken from the
 * `super` guard rather than passed in, so a controller cannot write someone else's name into the log. `null`
 * actor means a scheduled job (`saas:dun`, `saas:renew-subscriptions`), which is exactly what SCHEMA §2.13 wants.
 */
final class CentralAudit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        CentralAuditAction $action,
        ?Tenant $tenant = null,
        ?Model $auditable = null,
        ?array $before = null,
        ?array $after = null,
        ?int $superAdminId = null,
    ): AuditLogCentral {
        $request = app()->bound('request') ? app('request') : null;

        return AuditLogCentral::query()->create([
            'super_admin_id' => $superAdminId ?? $this->currentSuperAdminId(),
            'tenant_id' => $tenant?->id,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable === null ? null : (int) $auditable->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? $request->userAgent() : null,
            'request_id' => $request instanceof Request ? $request->attributes->get('request_id') : null,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    public function currentSuperAdminId(): ?int
    {
        $admin = Auth::guard('super')->user();

        return $admin instanceof SuperAdmin ? $admin->id : null;
    }
}
