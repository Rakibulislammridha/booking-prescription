<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

/**
 * The outcome of one DNS check. `reason` distinguishes "nothing published" from "someone else's token", which is
 * the difference between "you have not done it yet" and "you copied the wrong value" in the UI.
 */
final readonly class DomainVerificationResult
{
    /** @param  array<int, string>  $records */
    public function __construct(
        public bool $verified,
        public string $checkedName,
        public string $reason,
        public array $records = [],
    ) {}
}
