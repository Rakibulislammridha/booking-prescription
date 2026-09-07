<?php

declare(strict_types=1);

namespace App\Domain\Reception\Sync;

use App\Domain\Reception\Enums\ConflictResolution;

/** The receptionist's decision for a conflict card (OFFLINE §7.4 / §8). */
final readonly class Resolution
{
    /** @param  array<string, mixed>  $params */
    public function __construct(public ConflictResolution $resolution, public array $params = []) {}

    public function param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }

    public function string(string $key): ?string
    {
        $value = $this->param($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
