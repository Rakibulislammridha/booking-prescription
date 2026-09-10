<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Data;

use SensitiveParameter;

/** What the console's Admins screen submits: identity, and either a password or "send them a link". */
final readonly class SuperAdminData
{
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter] public ?string $password = null,
    ) {}

    /** No password typed: the account is created without a usable one and a set-password link goes out. */
    public function sendsLink(): bool
    {
        return $this->password === null || $this->password === '';
    }
}
