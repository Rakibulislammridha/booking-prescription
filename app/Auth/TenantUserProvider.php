<?php

declare(strict_types=1);

namespace App\Auth;

use App\Tenancy\Facades\Tenancy;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Eloquent provider for tenant-schema identities (staff, patients, reception devices). Without an active tenant
 * there is nobody to resolve: every lookup answers null instead of throwing TenancyNotInitialized, so a stray
 * `login_web_*` marker on a central host is "not authenticated", not HTTP 500 (CrossTenantIdentityTest).
 */
final class TenantUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        return Tenancy::check() ? parent::retrieveById($identifier) : null;
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        return Tenancy::check() ? parent::retrieveByToken($identifier, $token) : null;
    }

    /** @param  array<string, mixed>  $credentials */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        return Tenancy::check() ? parent::retrieveByCredentials($credentials) : null;
    }
}
