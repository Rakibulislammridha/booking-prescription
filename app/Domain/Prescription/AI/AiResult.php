<?php

declare(strict_types=1);

namespace App\Domain\Prescription\AI;

/** What an AiAssistant call returned; `unavailable()` when no provider is configured (PRESCRIPTION.md §5.7). */
final readonly class AiResult
{
    /** @param  array<string, mixed>  $payload  {lines: [...]} | {items: [{label, icd10_code, rationale}]} */
    public function __construct(
        public bool $available,
        public array $payload,
        public string $provider = 'null',
        public string $model = 'none',
        public string $prompt = '',
        public string $rawResponse = '',
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $latencyMs = null,
        public ?string $requestId = null,
    ) {}

    public static function unavailable(): self
    {
        return new self(false, []);
    }
}
