<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\SuperAdmin;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

/**
 * `public.audit_logs_central` with the console's filters: clinic, operator, action, a date range (Dhaka calendar
 * days) and free text on the TARGET — the auditable's class and id, and the before/after documents, so "which
 * rows touched plan `pro`" or "everything about invoice 4410" is one search. Every filter is indexed or bounded;
 * the free-text one walks jsonb, which is fine for a table that gains a few hundred rows a day.
 *
 * @phpstan-type Filters array{tenant: string|null, admin: int|null, action: string|null, from: string|null, to: string|null, q: string|null}
 */
final class AuditLogSearch
{
    public const PER_PAGE = 50;

    /** The export is streamed in chunks and capped: a CSV is a working file, not a database dump. */
    public const EXPORT_LIMIT = 20000;

    /**
     * @param  Filters  $filters
     * @return LengthAwarePaginator<int, AuditLogCentral>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->query($filters)->paginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * @param  Filters  $filters
     * @return LazyCollection<int, AuditLogCentral>
     */
    public function stream(array $filters): LazyCollection
    {
        return $this->query($filters)->limit(self::EXPORT_LIMIT)->lazy(500);
    }

    /** @param  Filters  $filters */
    public function count(array $filters): int
    {
        return $this->query($filters)->count();
    }

    /** @return array<string, mixed> */
    public function present(AuditLogCentral $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action->value,
            'actor' => $log->superAdmin?->name,
            'actor_id' => $log->super_admin_id,
            'tenant' => $log->tenant === null ? null : ['public_id' => $log->tenant->public_id, 'name' => $log->tenant->name, 'slug' => $log->tenant->slug],
            'auditable_type' => $log->auditable_type,
            'auditable_id' => $log->auditable_id,
            'before' => $log->before,
            'after' => $log->after,
            'ip' => $log->getAttribute('ip'),
            'user_agent' => $log->getAttribute('user_agent'),
            'request_id' => $log->getAttribute('request_id'),
            'occurred_at' => $log->getAttribute('occurred_at')?->toIso8601String(),
        ];
    }

    /**
     * What the filter bar offers: the operators, and the clinics that have ever appeared in the log.
     *
     * @return array{admins: array<int, array{id: int, name: string, is_active: bool}>, tenants: array<int, array{public_id: string, name: string, slug: string}>}
     */
    public function options(): array
    {
        $tenantIds = AuditLogCentral::query()->whereNotNull('tenant_id')->distinct()->pluck('tenant_id');

        return [
            'admins' => SuperAdmin::query()->withTrashed()->orderBy('name')->get(['id', 'name', 'is_active'])
                ->map(fn (SuperAdmin $a) => ['id' => $a->id, 'name' => $a->name, 'is_active' => $a->is_active])->all(),
            'tenants' => Tenant::query()->withTrashed()->whereIn('id', $tenantIds)->orderBy('name')->limit(500)->get(['public_id', 'name', 'slug'])
                ->map(fn (Tenant $t) => ['public_id' => $t->public_id, 'name' => $t->name, 'slug' => $t->slug])->all(),
        ];
    }

    /**
     * @param  Filters  $filters
     * @return Builder<AuditLogCentral>
     */
    private function query(array $filters): Builder
    {
        $zone = 'Asia/Dhaka';

        return AuditLogCentral::query()
            ->with(['superAdmin' => fn ($q) => $q->withTrashed(), 'tenant' => fn ($q) => $q->withTrashed()])
            ->when($filters['action'] !== null && $filters['action'] !== '' && CentralAuditAction::tryFrom($filters['action']) !== null, fn ($q) => $q->where('action', $filters['action']))
            ->when($filters['tenant'] !== null && $filters['tenant'] !== '', fn ($q) => $q->whereIn('tenant_id', fn ($s) => $s->select('id')->from('public.tenants')->where('public_id', $filters['tenant'])))
            ->when($filters['admin'] !== null, fn ($q) => $q->where('super_admin_id', $filters['admin']))
            ->when($filters['from'] !== null && $filters['from'] !== '', fn ($q) => $q->where('occurred_at', '>=', CarbonImmutable::parse((string) $filters['from'], $zone)->startOfDay()->utc()))
            ->when($filters['to'] !== null && $filters['to'] !== '', fn ($q) => $q->where('occurred_at', '<=', CarbonImmutable::parse((string) $filters['to'], $zone)->endOfDay()->utc()))
            ->when($filters['q'] !== null && trim($filters['q']) !== '', function ($q) use ($filters): void {
                $term = trim((string) $filters['q']);
                $like = '%'.mb_strtolower($term).'%';

                $q->where(function ($w) use ($term, $like): void {
                    $w->whereRaw('lower(auditable_type) like ?', [$like])
                        ->orWhereRaw('lower(coalesce(before::text, \'\')) like ?', [$like])
                        ->orWhereRaw('lower(coalesce(after::text, \'\')) like ?', [$like]);

                    if (ctype_digit($term)) {
                        $w->orWhere('auditable_id', (int) $term);
                    }
                });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }
}
