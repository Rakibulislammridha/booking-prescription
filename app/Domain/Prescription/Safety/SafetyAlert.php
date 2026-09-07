<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety;

use App\Domain\Prescription\Enums\SafetySeverity as Severity;
use JsonSerializable;

/**
 * One graded finding with a stable fingerprint "{key}:{code}:{sorted generic ids}[:bucket]" (PRESCRIPTION.md §5.1).
 * Overrides are keyed by fingerprint; a changed generic or dose bucket drops the override and the alert returns.
 */
final class SafetyAlert implements JsonSerializable
{
    /**
     * @param  list<string>  $itemKeys
     * @param  list<int>  $genericIds
     * @param  array<string, mixed>  $evidence
     * @param  array{reason: string, by: int|null, at: string|null}|null  $overridden
     */
    public function __construct(
        public readonly string $key,
        public readonly string $code,
        public readonly Severity $severity,
        public readonly string $fingerprint,
        public readonly bool $overridable,
        public readonly string $title,
        public readonly string $message,
        public readonly string $messageBn,
        public readonly array $itemKeys,
        public readonly array $genericIds,
        public readonly array $evidence,
        public ?array $overridden = null,
    ) {}

    public function blocksIssue(): bool
    {
        return $this->severity === Severity::Critical && ($this->overridden === null || ! $this->overridable);
    }

    /** The `kind` stored in prescription_items.safety_overrides (SCHEMA §3.4). */
    public function kind(): string
    {
        return $this->key;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'code' => $this->code,
            'severity' => $this->severity->value,
            'fingerprint' => $this->fingerprint,
            'overridable' => $this->overridable,
            'title' => $this->title,
            'message' => $this->message,
            'message_bn' => $this->messageBn,
            'item_keys' => $this->itemKeys,
            'generic_ids' => $this->genericIds,
            'evidence' => $this->evidence,
            'overridden' => $this->overridden,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
}
