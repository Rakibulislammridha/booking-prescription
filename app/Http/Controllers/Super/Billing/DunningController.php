<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Billing;

use App\Domain\SaaS\Actions\Billing\RunDunningForTenant;
use App\Domain\SaaS\Queries\BillingDunningQueue;
use App\Domain\SaaS\Support\DunningSchedule;
use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dunning queue: what the next `saas:dun` run will do, per clinic — and a button that does that clinic's
 * share of it now, through the same Actions. The ladder's constants are sent so the page can explain the
 * schedule in words rather than hard-code "day 3".
 */
final class DunningController extends Controller
{
    public function index(BillingDunningQueue $queue): Response
    {
        return Inertia::render('Super/Billing/Dunning', [
            'queue' => $queue->preview(),
            'totals' => $queue->totals(),
            'schedule' => [
                'net_days' => DunningSchedule::NET_DAYS,
                'steps' => DunningSchedule::STEPS,
                'grace_days' => DunningSchedule::GRACE_DAYS,
            ],
        ]);
    }

    public function run(Tenant $tenant, RunDunningForTenant $run): RedirectResponse
    {
        $result = $run->handle($tenant);

        return back()->with(
            $result['suspended'] ? 'flash.warning' : 'flash.success',
            __('super.billing.dunning.flash.ran', ['clinic' => $tenant->name, 'notices' => (string) $result['notices']]),
        );
    }
}
