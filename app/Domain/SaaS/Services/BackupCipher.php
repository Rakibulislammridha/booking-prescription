<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\SaaS\Enums\BackupEncryption;
use App\Domain\SaaS\Exceptions\BackupEncryptionUnavailable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Streaming authenticated encryption for tenant dumps (ARCHITECTURE §8.2/§8.8).
 *
 * ARCHITECTURE originally specified `age`. There is no `age` binary on the platform host and no way to build one,
 * so the dumps were going to the `backups` disk in the clear — a whole clinic's medical records, one leaked bucket
 * away. libsodium ships with PHP and `crypto_secretstream_xchacha20poly1305` is the right primitive for the job:
 * a dump is a large file that must be read back in order, and a secretstream gives per-chunk authentication plus a
 * FINAL tag, so a truncated object is a decryption error rather than half a clinic.
 *
 * ON-DISK FORMAT (v1), little in it by accident:
 *
 *     "BPBACKUP"                 8 bytes   magic — the restore path detects it and old plaintext dumps still work
 *     0x01                       1 byte    format version — a future change is detectable rather than garbage
 *     header                    24 bytes   crypto_secretstream_xchacha20poly1305 header (the stream nonce)
 *     repeated to EOF:
 *       length                   4 bytes   uint32 big-endian, length of the chunk that follows
 *       chunk             length bytes     1 MiB of plaintext + 17 bytes of Poly1305 tag; the last one is TAG_FINAL
 *
 * The length prefix is not strictly needed for a fixed chunk size, but it makes the file self-describing: a later
 * version may change `saas.backups.chunk_bytes` without making every existing object unreadable.
 *
 * Nothing here ever holds the whole file: both directions read and write in `saas.backups.chunk_bytes` steps.
 *
 * KEY. `config('saas.backups.encryption_key')` (`BP_BACKUP_KEY`), base64 of 32 raw bytes. Absent, the mode depends
 * on the environment: `local`/`testing` log a warning and write plaintext (that is what the test suite and a
 * developer's machine run on, and refusing there would only teach people to skip backups); every other
 * environment refuses. A key that is present but malformed is refused everywhere — a typo in a secret must never
 * degrade silently into "no encryption".
 */
final class BackupCipher
{
    public const MAGIC = 'BPBACKUP';

    public const VERSION = 1;

    /** magic + version byte. */
    public const PREFIX_BYTES = 9;

    /** Guard against a hostile length prefix asking for a gigabyte allocation. */
    private const MAX_CHUNK_BYTES = 64 * 1048576;

    /** What this environment will do with the next dump. Throws rather than degrade to plaintext outside local/testing. */
    public function resolveMode(): BackupEncryption
    {
        if ($this->configuredKey() !== null) {
            return BackupEncryption::XChaCha20Poly1305;
        }

        /** @var array<int, string> $allowed */
        $allowed = (array) config('saas.backups.plaintext_environments', []);
        $environment = (string) app()->environment();

        if (! in_array($environment, $allowed, true)) {
            throw BackupEncryptionUnavailable::missingKey($environment);
        }

        Log::warning('Tenant backup written UNENCRYPTED: no BP_BACKUP_KEY configured.', [
            'environment' => $environment,
            'allowed_plaintext_environments' => $allowed,
        ]);

        return BackupEncryption::None;
    }

    /** True when the file starts with this format's magic — the restore path's only test, so plaintext archives still restore. */
    public function isEncrypted(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            return fread($handle, strlen(self::MAGIC)) === self::MAGIC;
        } finally {
            fclose($handle);
        }
    }

    public function encrypt(string $source, string $destination): void
    {
        $key = $this->requireKey();
        $chunkBytes = $this->chunkBytes();
        $in = $this->open($source, 'rb');
        $out = $this->open($destination, 'wb');

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

            $this->write($out, self::MAGIC.chr(self::VERSION), $destination);
            $this->write($out, $header, $destination);

            $pending = (string) fread($in, $chunkBytes);

            do {
                $next = feof($in) ? '' : (string) fread($in, $chunkBytes);
                $isLast = $next === '';

                $chunk = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $pending,
                    '',
                    $isLast
                        ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                        : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                );

                $this->write($out, pack('N', strlen($chunk)).$chunk, $destination);
                $pending = $next;
            } while (! $isLast);
        } finally {
            sodium_memzero($key);
            fclose($in);
            fclose($out);
        }
    }

    /**
     * The inverse. Every failure mode — wrong key, flipped bit, truncated object, trailing junk — is an exception,
     * never a short file: a restore that silently feeds `pg_restore` half an archive is how a clinic loses a year.
     */
    public function decrypt(string $source, string $destination): void
    {
        $key = $this->requireKey();
        $in = $this->open($source, 'rb');
        $out = $this->open($destination, 'wb');

        try {
            $prefix = $this->readExactly($in, self::PREFIX_BYTES, 'header');

            if (! str_starts_with($prefix, self::MAGIC)) {
                throw new RuntimeException('Not an encrypted tenant backup: magic prefix missing.');
            }

            $version = ord($prefix[strlen(self::MAGIC)]);

            if ($version !== self::VERSION) {
                throw new RuntimeException("Unsupported tenant backup format version {$version}; this build reads version ".self::VERSION.'.');
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(
                $this->readExactly($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES, 'stream header'),
                $key,
            );

            $final = false;

            while (! $final) {
                $raw = fread($in, 4);

                if ($raw === '' || $raw === false) {
                    throw new RuntimeException('Tenant backup is truncated: the stream ended before its final chunk.');
                }

                if (strlen($raw) !== 4) {
                    throw new RuntimeException('Tenant backup is truncated: incomplete chunk length.');
                }

                $unpacked = unpack('N', $raw);

                if ($unpacked === false) {
                    throw new RuntimeException('Tenant backup is corrupt: unreadable chunk length.');
                }

                $length = (int) $unpacked[1];

                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > self::MAX_CHUNK_BYTES) {
                    throw new RuntimeException("Tenant backup is corrupt: implausible chunk length {$length}.");
                }

                $result = sodium_crypto_secretstream_xchacha20poly1305_pull(
                    $state,
                    $this->readExactly($in, $length, 'chunk'),
                );

                if ($result === false) {
                    throw new RuntimeException('Tenant backup failed authentication: wrong key, or the object has been altered.');
                }

                [$plain, $tag] = $result;

                $this->write($out, $plain, $destination);
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }

            if (fread($in, 1) !== '') {
                throw new RuntimeException('Tenant backup carries trailing bytes after its final chunk.');
            }
        } finally {
            sodium_memzero($key);
            fclose($in);
            fclose($out);
        }
    }

    private function chunkBytes(): int
    {
        return max(4096, (int) config('saas.backups.chunk_bytes', 1048576));
    }

    private function requireKey(): string
    {
        $key = $this->configuredKey();

        if ($key === null) {
            throw BackupEncryptionUnavailable::missingKey((string) app()->environment());
        }

        return $key;
    }

    /** The raw 32-byte key, or null when none is configured. A malformed value throws instead of returning null. */
    private function configuredKey(): ?string
    {
        $configured = trim((string) config('saas.backups.encryption_key', ''));

        if ($configured === '') {
            return null;
        }

        $key = base64_decode($configured, true);

        if ($key === false) {
            throw BackupEncryptionUnavailable::malformedKey('the value is not valid base64');
        }

        if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw BackupEncryptionUnavailable::malformedKey('it decodes to '.strlen($key).' bytes');
        }

        return $key;
    }

    /** @return resource */
    private function open(string $path, string $mode)
    {
        $handle = fopen($path, $mode);

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path} for backup encryption.");
        }

        return $handle;
    }

    /** @param  resource  $handle */
    private function readExactly($handle, int $length, string $what): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $read = fread($handle, $length - strlen($buffer));

            if ($read === false || $read === '') {
                throw new RuntimeException("Tenant backup is truncated: incomplete {$what}.");
            }

            $buffer .= $read;
        }

        return $buffer;
    }

    /** @param  resource  $handle */
    private function write($handle, string $bytes, string $path): void
    {
        $offset = 0;
        $total = strlen($bytes);

        while ($offset < $total) {
            $written = fwrite($handle, substr($bytes, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException("Cannot write to {$path}: the destination stopped accepting bytes.");
            }

            $offset += $written;
        }
    }
}
