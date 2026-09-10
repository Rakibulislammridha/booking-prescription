<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** The user public_id in the URL names nobody in THIS clinic — or a soft-deleted account nothing may wake. */
final class StaffUserNotFound extends DomainException
{
    public function __construct(public readonly string $publicId)
    {
        parent::__construct((string) __('super.tenants.staff.error.not_found'));
    }

    public function code(): string
    {
        return 'saas.tenants.staff_not_found';
    }

    public function status(): int
    {
        return 404;
    }
}
