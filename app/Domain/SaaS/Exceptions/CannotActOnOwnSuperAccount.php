<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** An operator cannot deactivate, delete or strip the second factor of the account they are signed in with. */
final class CannotActOnOwnSuperAccount extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('super.admins.error.self'));
    }

    public function code(): string
    {
        return 'super.admins.self';
    }
}
