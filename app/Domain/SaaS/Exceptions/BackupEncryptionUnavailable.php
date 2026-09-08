<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Exceptions;

use RuntimeException;

/**
 * `BP_BACKUP_KEY` is missing or unusable, so a dump cannot be encrypted.
 *
 * Deliberately NOT a `App\Domain\Shared\Exceptions\DomainException`: it is not a business rule a clinic user can
 * violate and it has no translated message, it is a deployment fault raised inside a console command or a queue
 * worker. It exists as a named class so the reason is legible in a stack trace and in the `tenant_backups.error`
 * column, which is the only place an operator will ever see it.
 */
final class BackupEncryptionUnavailable extends RuntimeException
{
    public static function missingKey(string $environment): self
    {
        return new self(
            "No backup encryption key configured (config('saas.backups.encryption_key') / BP_BACKUP_KEY) and the "
            ."'{$environment}' environment is not allowed to write plaintext dumps. Generate one with "
            ."php -r \"echo base64_encode(random_bytes(32));\" and set BP_BACKUP_KEY. Refusing to write a clinic's "
            .'records to the backups disk in the clear.'
        );
    }

    public static function malformedKey(string $why): self
    {
        return new self("BP_BACKUP_KEY is set but unusable: {$why}. Expected base64 of exactly 32 raw bytes.");
    }
}
