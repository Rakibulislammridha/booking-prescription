<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Enums\DomainVerificationStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Models\Central\CatalogReconciliationReport;
use App\Models\Central\CustomBrandPromotion;
use App\Models\Central\Domain;
use App\Models\Central\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's "needs a human" list. Every entry is a real count from a real query, and every entry links to
 * the screen where the work is done; an entry whose count is zero is dropped, so a quiet morning is a short
 * list rather than a wall of reassuring zeros.
 *
 * `route`/`params` name a console route (rendered through Ziggy on the client); `href` is a plain URL for the
 * one destination that is not an Inertia page (Horizon).
 *
 * @phpstan-type Item array{key: string, count: int, severity: 'error'|'warning'|'info', route: string|null, params: array<string, string>, href: string|null, amount_paisa?: int}
 */
final class AttentionItems
{
    public function __construct(private readonly SuperTwoFactor $twoFactor) {}

    /**
     * @param  array<string, mixed>  $kpis  the tiles already computed by PlatformKpis::all(), so nothing is counted twice
     * @return array<int, Item> most urgent first
     */
    public function all(array $kpis): array
    {
        $items = [];
        $now = CarbonImmutable::now();

        /** @var array{tenants: int, invoices: int, paisa: int} $pastDue */
        $pastDue = $kpis['past_due'];
        /** @var array{never: int, stale: int} $backups */
        $backups = $kpis['backups'];
        /** @var array{available: bool, running: bool, depth: int, longest_wait: int, failed: int, horizon_url: string} $queue */
        $queue = $kpis['queue'];

        $push = function (string $key, int $count, string $severity, ?string $route, array $params = [], ?string $href = null, ?int $paisa = null) use (&$items): void {
            if ($count <= 0) {
                return;
            }

            $item = ['key' => $key, 'count' => $count, 'severity' => $severity, 'route' => $route, 'params' => $params, 'href' => $href];

            if ($paisa !== null) {
                $item['amount_paisa'] = $paisa;
            }

            $items[] = $item;
        };

        $push('overdue_invoices', $pastDue['invoices'], 'error', 'super.tenants.index', ['status' => TenantStatus::PastDue->value], null, $pastDue['paisa']);
        $push('failed_jobs', $queue['failed'], 'error', null, [], $queue['horizon_url'].'/failed');
        $push('horizon_down', $queue['available'] && ! $queue['running'] ? 1 : 0, 'error', null, [], $queue['horizon_url']);
        $push('queue_backlog', $queue['longest_wait'] >= 60 || $queue['depth'] >= 100 ? $queue['depth'] : 0, 'warning', null, [], $queue['horizon_url']);
        $push('over_limit', (int) $kpis['over_limit'], 'warning', 'super.usage.index', ['filter' => 'over']);
        $push('sms_near_limit', (int) $kpis['sms_near_limit'], 'warning', 'super.usage.index', ['metric' => 'sms_credits', 'filter' => 'near']);
        $push('trials_ending', (int) $kpis['trials_ending_7d'], 'warning', 'super.tenants.index', ['status' => TenantStatus::Trial->value]);
        $push('backups_never', $backups['never'], 'warning', 'super.tenants.index');
        $push('backups_stale', $backups['stale'], 'warning', 'super.tenants.index');
        $push('domains_failed', Domain::query()->where('verification_status', DomainVerificationStatus::Failed->value)->count(), 'warning', 'super.tenants.index');
        $push('admins_without_2fa', $this->adminsWithoutTwoFactor(), 'warning', 'super.admins.index');
        $push('suspended_recent', (int) DB::connection('pgsql')->table('public.tenants')->whereNull('deleted_at')
            ->where('status', TenantStatus::Suspended->value)->where('suspended_at', '>=', $now->subDays(7))->count(), 'info', 'super.tenants.index', ['status' => TenantStatus::Suspended->value]);
        $push('promotions_pending', CustomBrandPromotion::query()->where('status', 'pending')->count(), 'info', 'super.catalog.review');
        $push('reconciliation_open', CatalogReconciliationReport::query()->whereNull('resolved_at')->where('status', '!=', 'clean')->count(), 'info', 'super.catalog.reconciliation.index');

        return $items;
    }

    /** Active operators with no working factor while the policy asks for one. */
    private function adminsWithoutTwoFactor(): int
    {
        if (! $this->twoFactor->policy()->challengesEnrolled()) {
            return 0;
        }

        return SuperAdmin::query()->where('is_active', true)->get()
            ->filter(fn (SuperAdmin $admin): bool => ! $this->twoFactor->enabled($admin))
            ->count();
    }
}
