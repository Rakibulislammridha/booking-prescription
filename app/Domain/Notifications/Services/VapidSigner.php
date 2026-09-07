<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Support\Ec;
use RuntimeException;

/**
 * RFC 8292 VAPID: signs the `Authorization: vapid t=<JWT>, k=<public key>` header a push service requires to accept
 * a request. ES256 over {aud: <push service origin>, exp: now + ttl, sub: mailto:… }.
 *
 * The key pair is platform configuration (`notifications.push.vapid.*`, sourced from the environment), NOT tenant
 * data: one application server identity serves every clinic, exactly as one Reverb app serves every tenant.
 */
final class VapidSigner
{
    public function __construct(
        private readonly string $publicKey,      // base64url raw 65-byte point
        private readonly string $privateKey,     // base64url raw 32-byte scalar
        private readonly string $subject,        // mailto: or https: contact
        private readonly int $expirySeconds = 43200,
    ) {}

    public function isConfigured(): bool
    {
        return $this->publicKey !== '' && $this->privateKey !== '' && $this->subject !== '';
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /** @return array<string, string> the headers to add to the push request */
    public function headers(string $endpoint, ?int $now = null): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('web push: VAPID keys are not configured');
        }

        $parts = parse_url($endpoint);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('web push: endpoint is not a URL');
        }

        $payload = [
            'aud' => $parts['scheme'].'://'.$parts['host'],
            'exp' => ($now ?? time()) + $this->expirySeconds,
            'sub' => $this->subject,
        ];

        $signingInput = Ec::base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            .'.'.Ec::base64UrlEncode((string) json_encode($payload));

        $scalar = Ec::base64UrlDecode($this->privateKey);
        $point = Ec::base64UrlDecode($this->publicKey);
        $key = openssl_pkey_get_private(Ec::privateKeyPem($scalar, $point));

        if ($key === false) {
            throw new RuntimeException('web push: VAPID private key could not be read');
        }

        $der = '';

        if (! openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('web push: VAPID signing failed');
        }

        $jwt = $signingInput.'.'.Ec::base64UrlEncode(Ec::derToJose($der));

        return ['Authorization' => "vapid t={$jwt}, k={$this->publicKey}"];
    }
}
