<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety;

/** One safety rule (PRESCRIPTION.md §5.1). Pure w.r.t. tenant data; reads the catalog through CatalogCache only. */
interface SafetyCheck
{
    public function key(): string;

    /** @return list<SafetyAlert> */
    public function run(SafetyContext $ctx): array;
}
