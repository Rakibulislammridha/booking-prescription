<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Tenants;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\DomainType;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\Tenancy\Exceptions\SlugReserved;
use App\Domain\Tenancy\Exceptions\SlugTaken;
use App\Domain\Tenancy\Rules\NotReservedSlug;
use App\Models\Central\Domain;
use App\Models\Central\Tenant;
use App\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

/**
 * Change a clinic's platform subdomain — `{slug}.{central}` — after creation.
 *
 * WHY THIS IS SAFE (the slug-rename decision, ARCHITECTURE §4). Everything durable about a tenant hangs off its
 * ID, not its slug: the schema is `tenant_{id}`, storage keys are `tenants/{id}/…`, search indexes are `t{id}_*`,
 * Pennant scopes are `tenant:{id}`, the Spatie cache key is the schema name, and sessions are bound to
 * `tenant_id`. The slug appears in exactly three places — `public.tenants.slug`, the `subdomain` row of
 * `public.domains`, and the per-host resolver cache — and the two models forget their old AND new host keys on
 * `saved`, so the rename is one transaction over two rows with no cache to chase.
 *
 * WHAT IT COSTS, spelled out in the confirm dialog: the old address stops answering at once (its cache entry is
 * dropped and no row claims it), every staff session on the old host is orphaned because the session cookie is
 * named after the slug (`bp_{slug}_session`), and any SMS or printed link that carried the old host is dead unless
 * a verified custom domain is the primary. And because `ProvisionTenant` only refuses slugs that exist (including
 * soft-deleted ones), the released slug is free for the next clinic to claim — so it is renamed, not parked.
 */
final class RenameTenantSlug
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly CentralAudit $audit,
    ) {}

    public function handle(Tenant $tenant, string $slug, ?int $superAdminId = null): Tenant
    {
        $slug = mb_strtolower(trim($slug));

        if (! NotReservedSlug::isWellFormed($slug) || NotReservedSlug::isReserved($slug)) {
            throw new SlugReserved($slug);
        }

        if ($slug !== $tenant->slug && Tenant::withTrashed()->where('slug', $slug)->exists()) {
            throw new SlugTaken($slug);
        }

        if ($slug === $tenant->slug) {
            return $tenant;
        }

        $central = (string) config('tenancy.central_domain');
        $oldHost = $tenant->primaryHost();
        $newHost = $slug.'.'.$central;

        DB::connection('pgsql')->transaction(function () use ($tenant, $slug, $oldHost, $newHost): void {
            $subdomain = Domain::query()->where('tenant_id', $tenant->id)->where('type', DomainType::Subdomain->value)->where('domain', $oldHost)->first();
            $subdomain?->forceFill(['domain' => $newHost])->save();

            $tenant->forceFill(['slug' => $slug])->save();
        });

        // The models already forget their hosts on save; this is the belt to that braces for the old host, which
        // no row names any more and which must stop resolving on the very next request.
        $this->resolver->forget($oldHost);
        $this->resolver->forget($newHost);

        $this->audit->record(
            CentralAuditAction::Update,
            $tenant,
            $tenant,
            ['slug' => substr($oldHost, 0, -strlen('.'.$central)), 'host' => $oldHost],
            ['slug' => $slug, 'host' => $newHost],
            $superAdminId,
        );

        return $tenant->refresh();
    }
}
