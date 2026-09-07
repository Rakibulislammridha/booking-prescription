<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class TemplateNameTaken extends DomainException
{
    public function __construct(string $name)
    {
        parent::__construct("A template named \"{$name}\" already exists.");
    }

    public function code(): string
    {
        return 'prescriptions.template_name_taken';
    }

    public function status(): int
    {
        return 409;
    }
}
