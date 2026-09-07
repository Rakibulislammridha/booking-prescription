<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

/** One parser finding (PRESCRIPTION.md §2.11 ParseIssue). `error` blocks the line; `warning`/`info` never do. */
final readonly class ParseIssue
{
    /** @param  array{0: int, 1: int}|null  $span */
    public function __construct(
        public string $code,
        public string $severity,
        public ?string $token,
        public ?array $span,
        public string $message,
        public string $messageBn,
        public ?string $suggestion = null,
    ) {}

    public function isError(): bool
    {
        return $this->severity === 'error';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'token' => $this->token,
            'span' => $this->span,
            'message' => $this->message,
            'message_bn' => $this->messageBn,
            'suggestion' => $this->suggestion,
        ];
    }

    /** @param  array<string, mixed>  $a */
    public static function fromArray(array $a): self
    {
        $span = isset($a['span']) && is_array($a['span']) && count($a['span']) === 2 ? [(int) $a['span'][0], (int) $a['span'][1]] : null;

        return new self((string) $a['code'], (string) $a['severity'], isset($a['token']) ? (string) $a['token'] : null, $span, (string) ($a['message'] ?? ''), (string) ($a['message_bn'] ?? ''), isset($a['suggestion']) ? (string) $a['suggestion'] : null);
    }
}
