<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Domain\SaaS\Queries\PlanCatalog;
use App\Domain\SaaS\Support\CentralCopy;
use App\Http\Controllers\Central\Concerns\BuildsCentralLinks;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** The platform's front door on the bare central host (BRIEF §5.M "marketing site"). Site bundle, no MUI. */
final class HomeController extends Controller
{
    use BuildsCentralLinks;

    public function __invoke(PlanCatalog $catalog): Response
    {
        return Inertia::render('Central/Home', [
            'plans' => $catalog->publicPlans(),
            'links' => $this->centralLinks(),
            'platform' => $this->platformProps(),
            'highlights' => ['marketing.highlight.1', 'marketing.highlight.2', 'marketing.highlight.3'],
            'copy' => CentralCopy::for('home'),
        ]);
    }
}
