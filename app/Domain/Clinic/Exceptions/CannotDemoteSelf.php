<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * BRIEF §5.A / §5.N: the last thing a hospital admin may do is lock themselves out of the clinic they administer.
 * Deactivating yourself is CannotDeactivateSelf; taking the admin role off yourself is this one.
 */
final class CannotDemoteSelf extends DomainException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(trim('You cannot remove your own administrator role. '.$detail));
    }

    public function code(): string
    {
        return 'clinic.users.cannot_demote_self';
    }
}
