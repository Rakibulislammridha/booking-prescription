<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * `users.email` is unique per clinic schema. The check cannot be a FormRequest rule on the super host — the
 * console's request runs on `public`, where there is no `users` table — so the action, which is inside the
 * clinic's schema, is the one that finds out.
 */
final class StaffEmailTaken extends DomainException
{
    public function __construct(public readonly string $email)
    {
        parent::__construct((string) __('super.tenants.staff.error.email_taken', ['email' => $email]));
    }

    public function code(): string
    {
        return 'saas.tenants.staff_email_taken';
    }

    public function status(): int
    {
        return 409;
    }
}
