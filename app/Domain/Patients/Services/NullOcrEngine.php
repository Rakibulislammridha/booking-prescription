<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Contracts\OcrEngine;

/**
 * The unconfigured default (PRESCRIPTION.md §8): no cloud engine, so a photographed report is named by type and
 * upload date and marked `skipped`. Bound whenever neither the tenant nor the platform has credentials, which is
 * also what the whole test suite runs on — nothing here can make a network call.
 */
final class NullOcrEngine implements OcrEngine
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function supports(string $mimeType): bool
    {
        return false;
    }

    public function text(string $bytes, string $mimeType): ?string
    {
        return null;
    }
}
