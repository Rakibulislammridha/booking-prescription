<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Tenant setting serial.vip_enabled = false (SERIAL_ENGINE §7.3). */
final class VipDisabled extends DomainException
{
    public function __construct()
    {
        parent::__construct('The VIP priority is disabled for this clinic.');
    }

    public function code(): string
    {
        return 'serials.vip_disabled';
    }
}
