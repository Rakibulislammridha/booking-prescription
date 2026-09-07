<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Data;

use App\Domain\Notifications\Enums\NotificationLogStatus;

/**
 * The outcome of ONE attempt. `permanent` is the whole point of the class: a rejected number, a bad template or a
 * revoked token must never be retried, while a 503 or a timeout must be. Drivers classify; the pipeline obeys.
 */
final readonly class DeliveryResult
{
    /**
     * @param  array<string, mixed>|null  $response
     * @param  array<string, mixed>|null  $request
     */
    public function __construct(
        public NotificationLogStatus $status,
        public bool $permanent = false,
        public ?string $providerMessageId = null,
        public ?array $response = null,
        public ?string $errorCode = null,
        public ?int $latencyMs = null,
        public ?array $request = null,
        public ?int $costPaisa = null,
    ) {}

    /** @param  array<string, mixed>|null  $response */
    public static function sent(?string $providerMessageId = null, ?array $response = null, ?int $latencyMs = null, ?int $costPaisa = null): self
    {
        return new self(NotificationLogStatus::Sent, providerMessageId: $providerMessageId, response: $response, latencyMs: $latencyMs, costPaisa: $costPaisa);
    }

    /** A provider refusal that repeating cannot fix: bad number, bad template, revoked credentials, gone endpoint. */
    /** @param  array<string, mixed>|null  $response */
    public static function rejected(string $errorCode, ?array $response = null, ?int $latencyMs = null): self
    {
        return new self(NotificationLogStatus::Rejected, permanent: true, response: $response, errorCode: $errorCode, latencyMs: $latencyMs);
    }

    /** A transient failure: timeout, 5xx, throttle, malformed body. Worth another attempt with backoff. */
    /** @param  array<string, mixed>|null  $response */
    public static function failed(string $errorCode, ?array $response = null, ?int $latencyMs = null): self
    {
        return new self(NotificationLogStatus::Failed, permanent: false, response: $response, errorCode: $errorCode, latencyMs: $latencyMs);
    }

    public function isSuccess(): bool
    {
        return in_array($this->status, [NotificationLogStatus::Sent, NotificationLogStatus::Delivered], true);
    }

    public function isRetryable(): bool
    {
        return ! $this->isSuccess() && ! $this->permanent;
    }

    /** @param  array<string, mixed>  $request */
    public function withRequest(array $request): self
    {
        return new self($this->status, $this->permanent, $this->providerMessageId, $this->response, $this->errorCode, $this->latencyMs, $request, $this->costPaisa);
    }

    public function withLatency(int $latencyMs): self
    {
        return new self($this->status, $this->permanent, $this->providerMessageId, $this->response, $this->errorCode, $latencyMs, $this->request, $this->costPaisa);
    }
}
