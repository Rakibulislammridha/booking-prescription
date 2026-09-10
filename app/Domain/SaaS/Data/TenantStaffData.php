<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Data;

use App\Domain\Clinic\Enums\Role;

/**
 * A staff account created for a clinic from the super console. `password` null means the operator chose a
 * set-password link; either way the account starts with `must_change_password` on, because a credential the
 * platform team typed or generated is a bootstrap, not the user's own.
 */
final readonly class TenantStaffData
{
    public function __construct(
        public string $name,
        public string $email,
        public Role $role,
        public ?string $password,
        public ?string $mobile = null,
        public string $locale = 'bn',
    ) {}
}
