<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Tenants;

use App\Domain\SaaS\Actions\Tenants\RenameTenantSlug;
use App\Domain\Tenancy\Exceptions\SlugReserved;
use App\Domain\Tenancy\Exceptions\SlugTaken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Tenants\RenameTenantSlugRequest;
use App\Models\Central\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/** "Change subdomain" — the one way a clinic's slug changes after creation (see `RenameTenantSlug` for why it is safe). */
final class SlugController extends Controller
{
    public function __invoke(RenameTenantSlugRequest $request, Tenant $tenant, RenameTenantSlug $rename): RedirectResponse
    {
        $old = $tenant->primaryHost();

        try {
            $renamed = $rename->handle($tenant, $request->slug(), (int) $request->user('super')?->getAuthIdentifier());
        } catch (SlugTaken|SlugReserved) {
            throw ValidationException::withMessages(['slug' => __('saas.onboarding.slug_taken')]);
        }

        return redirect()->route('super.tenants.show', ['tenant' => $renamed->public_id])
            ->with('flash.warning', __('super.tenants.flash.renamed', ['old' => $old, 'new' => $renamed->primaryHost()]));
    }
}
