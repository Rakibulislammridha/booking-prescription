<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    /** Horizon lives on super.{central}; only an authenticated super admin may view it. */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null) => Auth::guard('super')->check());
    }
}
