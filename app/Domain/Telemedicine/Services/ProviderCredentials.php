<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Services;

use App\Domain\Telemedicine\Enums\TelemedicineProvider;

/** One clinic's video credentials, resolved from tenant settings with config fallbacks. */
final readonly class ProviderCredentials
{
    public function __construct(
        public TelemedicineProvider $provider,
        public string $host = '',
        public string $apiKey = '',
        public string $apiSecret = '',
        public bool $recording = false,
        public int $maxMinutes = 45,
        public int $tokenTtlSeconds = 900,
    ) {}

    public function isComplete(): bool
    {
        return $this->host !== '' && $this->apiKey !== '' && $this->apiSecret !== '';
    }

    public function withProvider(TelemedicineProvider $provider): self
    {
        return new self($provider, $this->host, $this->apiKey, $this->apiSecret, $this->recording, $this->maxMinutes, $this->tokenTtlSeconds);
    }
}
