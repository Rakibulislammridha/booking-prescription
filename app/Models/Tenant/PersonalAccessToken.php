<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\RequiresTenancy;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Staff API tokens and reception device tokens (tenant personal_access_tokens; the tokenable morph decides which).
 */
final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use RequiresTenancy;

    protected $connection = 'pgsql';

    protected $table = 'personal_access_tokens';
}
