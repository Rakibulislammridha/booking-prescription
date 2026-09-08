<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

/**
 * How the object at `tenant_backups.storage_path` is protected at rest (SCHEMA §2.11).
 *
 * Recorded per row rather than inferred from the file name or the current configuration: the question an
 * operator asks after a key change or an incident is "which of these objects is in the clear", and only the
 * row that was written at the time can answer it.
 */
enum BackupEncryption: string
{
    /** Plaintext `pg_dump` custom archive. Only ever written in local/testing (BackupCipher::resolveMode()). */
    case None = 'none';

    /** libsodium `crypto_secretstream_xchacha20poly1305`, chunked and authenticated (BackupCipher). */
    case XChaCha20Poly1305 = 'xchacha20poly1305';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** The suffix appended to the object key so a bucket listing is self-describing. */
    public function fileSuffix(): string
    {
        return $this === self::None ? '' : '.enc';
    }
}
