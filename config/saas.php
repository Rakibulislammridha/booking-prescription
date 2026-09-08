<?php

declare(strict_types=1);

// config('saas.*') — the control-plane module's own keys (CONVENTIONS §11). Secrets arrive from the
// environment here and nowhere else: `env()` is legal in config/*, and only in config/*.
return [

    'backups' => [

        // Base64 of 32 raw bytes — the XChaCha20-Poly1305 key every `tenants:backup` dump is encrypted with
        // (App\Domain\SaaS\Services\BackupCipher, ARCHITECTURE §8.2/§8.8). Generate with:
        //     php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
        // Empty is a hard failure outside local/testing: a clinic's whole record must never reach a bucket
        // in the clear. It is a PLATFORM key, not a per-tenant one, and losing it makes every dump unusable —
        // it belongs in the deployment's secret store together with APP_KEY, and it is rotated by re-dumping,
        // not by re-encrypting (old objects keep the key they were written with; keep the retired key readable
        // for as long as objects encrypted with it are still inside their retention window).
        'encryption_key' => env('BP_BACKUP_KEY', ''),

        // Plaintext bytes per libsodium secretstream chunk. A dump is streamed through this buffer, never
        // loaded whole: a clinic with three years of serials does not fit in a queue worker's memory limit.
        'chunk_bytes' => 1048576,

        // Environments allowed to fall back to an UNENCRYPTED dump when no key is configured. Every other
        // environment refuses the backup outright.
        'plaintext_environments' => ['local', 'testing'],
    ],

];
