<?php

declare(strict_types=1);

namespace App\Models\Central;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum tokens for super admins and platform integrations (public.personal_access_tokens).
 */
final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $connection = 'pgsql';

    protected $table = 'public.personal_access_tokens';
}
