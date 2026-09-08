<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Http\Controllers\Controller;
use App\Models\Central\AuditLogCentral;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** `public.audit_logs_central`, newest first — who did what to which clinic, from where. Read-only by design. */
final class AuditController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'action' => ['nullable', Rule::in(CentralAuditAction::values())],
            'tenant' => ['nullable', 'string', 'max:26'],
        ]);

        $rows = AuditLogCentral::query()
            ->with(['superAdmin:id,name', 'tenant:id,public_id,name,slug'])
            ->when($validated['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($validated['tenant'] ?? null, fn ($q, $t) => $q->whereIn('tenant_id', fn ($s) => $s->select('id')->from('public.tenants')->where('public_id', $t)))
            ->orderByDesc('occurred_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Super/Audit/Index', [
            'logs' => $rows->through(fn (AuditLogCentral $l) => [
                'id' => $l->id,
                'action' => $l->action->value,
                'actor' => $l->superAdmin?->name,
                'tenant' => $l->tenant === null ? null : ['public_id' => $l->tenant->public_id, 'name' => $l->tenant->name, 'slug' => $l->tenant->slug],
                'auditable_type' => $l->getAttribute('auditable_type'),
                'auditable_id' => $l->getAttribute('auditable_id'),
                'before' => $l->getAttribute('before'),
                'after' => $l->getAttribute('after'),
                'ip' => $l->getAttribute('ip'),
                'occurred_at' => $l->getAttribute('occurred_at')?->toIso8601String(),
            ])->items(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
            'filters' => ['action' => $validated['action'] ?? '', 'tenant' => $validated['tenant'] ?? ''],
            'actions' => CentralAuditAction::values(),
        ]);
    }
}
