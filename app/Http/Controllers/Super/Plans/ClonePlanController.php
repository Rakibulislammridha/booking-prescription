<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Plans;

use App\Domain\SaaS\Actions\Plans\ClonePlan;
use App\Http\Controllers\Controller;
use App\Models\Central\Plan;
use Illuminate\Http\RedirectResponse;

/** `POST plans/{plan}/clone` — a hidden copy, then straight into its editor. */
final class ClonePlanController extends Controller
{
    public function __invoke(Plan $plan, ClonePlan $clone): RedirectResponse
    {
        $copy = $clone->handle($plan);

        return redirect()->route('super.plans.edit', ['plan' => $copy->code])
            ->with('flash.success', __('saas.plans.flash.cloned', ['plan' => $plan->name, 'copy' => $copy->name]));
    }
}
