<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 409 — `expected_updated_at` ≠ the row's updated_at (another tab saved); the body carries the server draft. */
final class DraftConflict extends DomainException
{
    /** @param  array<string, mixed>  $serverDraft */
    public function __construct(public readonly array $serverDraft)
    {
        parent::__construct('The draft was updated elsewhere — reload.');
    }

    public function code(): string
    {
        return 'prescriptions.draft_conflict';
    }

    public function status(): int
    {
        return 409;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->code(), 'prescription' => $this->serverDraft], 409);
    }
}
