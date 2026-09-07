<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 422 `{errors: {"items.i1": [ParseIssue…]}}` — a server re-parse found `error` issues (PRESCRIPTION.md §2.13, §4.13). */
final class DraftParseFailed extends DomainException
{
    /** @param  array<string, list<array<string, mixed>>>  $errors  "items.{key}" → issues */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('One or more Rx lines could not be parsed.');
    }

    public function code(): string
    {
        return 'prescriptions.parse_error';
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->code(), 'errors' => $this->errors], 422);
    }
}
