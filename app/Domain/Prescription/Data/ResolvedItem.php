<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

/** One Rx line after the server resolved its DrugRef and re-parsed the shorthand (SaveDraft / check / issue). */
final readonly class ResolvedItem
{
    /**
     * @param  list<array{fingerprint: string, reason: string}>  $overrideRequests  what the client sent
     * @param  list<array<string, mixed>>  $existingOverrides  the row's stored safety_overrides
     */
    public function __construct(
        public string $key,
        public ?int $id,
        public int $sortOrder,
        public ?DrugRef $drug,
        public string $shorthand,
        public ParsedLine $parsed,
        public array $overrideRequests = [],
        public array $existingOverrides = [],
        public ?string $instructionBn = null,
    ) {}
}
