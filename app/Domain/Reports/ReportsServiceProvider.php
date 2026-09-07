<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Reports\Enums\ReportKind;
use App\Domain\Reports\Policies\ReportPolicy;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Reports module wiring (BRIEF §5.L).
 *
 * The abilities are GATES rather than a model policy because a report is not a row — there is nothing to bind
 * `{report}` to. They still live in a `ReportPolicy` class so the matrix reads like every other module's and can
 * be unit-tested on its own:
 *
 *   reports.section   the section exists for this user (PanelLayout's nav entry checks the permission itself)
 *   reports.open      one report family — money and clinical detail are separately gated
 *   reports.download  walking out with the file is its own permission
 *
 * The gate names deliberately DIFFER from the permission strings they check. A gate called `reports.view`
 * whose policy body calls `$user->can('reports.view')` recurses until the process dies, because spatie's
 * permission check goes back through the Gate — the two namespaces look alike and are not.
 */
final class ReportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('reports.section', [ReportPolicy::class, 'view']);
        Gate::define('reports.open', fn (User $user, ReportKind $kind): bool => app(ReportPolicy::class)->open($user, $kind));
        Gate::define('reports.download', fn (User $user, ReportKind $kind): bool => app(ReportPolicy::class)->export($user, $kind));
    }
}
