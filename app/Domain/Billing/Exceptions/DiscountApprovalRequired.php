<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Above settings.billing.discount_approval_threshold_paisa an approver is mandatory (SCHEMA §3.5). */
final class DiscountApprovalRequired extends DomainException
{
    /** @param  array<string, mixed>  $replace */
    public function __construct(array $replace = [])
    {
        parent::__construct(__('billing.errors.discount_approval_required', $replace));
    }

    public function code(): string
    {
        return 'billing.discount_approval_required';
    }

    public function status(): int
    {
        return 403;
    }
}
