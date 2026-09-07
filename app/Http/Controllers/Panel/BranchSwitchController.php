<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetActiveBranch;
use App\Http\Requests\Panel\SwitchBranchRequest;
use Illuminate\Http\RedirectResponse;

/**
 * PATCH /panel/branch (panel.branch.switch): the branch switcher in PanelLayout. Writes SetActiveBranch's session
 * key; the branch must be an active branch of this tenant (validated inside the tenant schema).
 */
final class BranchSwitchController extends Controller
{
    public function __invoke(SwitchBranchRequest $request): RedirectResponse
    {
        $request->session()->put(SetActiveBranch::SESSION_KEY, $request->branch()->id);

        return back(fallback: route('panel.dashboard', absolute: false));
    }
}
