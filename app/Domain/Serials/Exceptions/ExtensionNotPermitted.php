<?php

declare(strict_types=1);

namespace App\Domain\Serials\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** A receptionist asked for more than serial.receptionist_extension_limit without approval (SERIAL_ENGINE §3.4). */
final class ExtensionNotPermitted extends DomainException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct(sprintf('Receptionists may extend a session by at most %d serial(s) without approval.', $limit));
    }

    public function code(): string
    {
        return 'serials.extension_not_permitted';
    }

    public function status(): int
    {
        return 403;
    }
}
