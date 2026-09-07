<?php

declare(strict_types=1);

namespace App\Tenancy\Listeners;

use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Http\Middleware\EnsureSessionBelongsToTenant;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * On every Login (attempt, remember-me recaller, Auth::login) pin the session to the active tenant; central logins
 * (guard super) make sure no tenant is carried. EnsureSessionBelongsToTenant enforces it on later requests.
 */
final class BindSessionToTenant
{
    public function __construct(private readonly Application $app) {}

    public function handle(Login $event): void
    {
        if (! $this->app->bound('request')) {
            return;
        }

        /** @var Request $request */
        $request = $this->app->make('request');

        if (! $request->hasSession()) {
            return;
        }

        $session = $request->session();
        $tenantId = Tenancy::id();

        if ($tenantId === null) {
            $session->forget(EnsureSessionBelongsToTenant::SESSION_KEY);
        } else {
            $session->put(EnsureSessionBelongsToTenant::SESSION_KEY, $tenantId);
        }
    }
}
