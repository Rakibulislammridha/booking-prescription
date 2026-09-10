<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** An operator who has ever signed in or acted is deactivated, never deleted — their audit rows need an actor. */
final class SuperAdminInUse extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('super.admins.error.in_use'));
    }

    public function code(): string
    {
        return 'super.admins.in_use';
    }

    public function status(): int
    {
        return 409;
    }
}
