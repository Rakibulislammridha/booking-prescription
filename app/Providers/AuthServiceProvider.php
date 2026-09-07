<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\TenantUserProvider;
use App\Models\Central\PersonalAccessToken as CentralPersonalAccessToken;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

final class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Tenant-schema identities (staff, patients, devices): Eloquent, but null (not an exception) without a tenant.
        Auth::provider('tenant', fn (Application $app, array $config) => new TenantUserProvider($app->make('hash'), (string) $config['model']));

        // Central token model by default; Tenancy::initialize()/end() swap it per context (ARCHITECTURE §5.1).
        Sanctum::usePersonalAccessTokenModel(CentralPersonalAccessToken::class);

        // Stateful (cookie) Sanctum hosts: the central wildcard; ResolveTenant adds each resolved tenant host.
        $central = (string) config('tenancy.central_domain');
        $stateful = array_values(array_unique(array_filter(array_merge(
            (array) config('sanctum.stateful', []),
            [$central, '*.'.$central, 'localhost', '127.0.0.1'],
        ))));

        config(['sanctum.stateful' => $stateful]);

        // Staff password reset links point at the tenant host's panel (routes/panel/auth.php, broker `users`).
        ResetPassword::createUrlUsing(function (Authenticatable $user, string $token): string {
            $email = (string) $user->getAttribute('email');

            return Route::has('panel.password.reset')
                ? route('panel.password.reset', ['token' => $token, 'email' => $email])
                : url('/panel/reset-password/'.$token.'?email='.urlencode($email));
        });
    }
}
