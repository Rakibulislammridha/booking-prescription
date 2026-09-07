<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\InvoiceItemType;

/** One line to bill. `unitPricePaisa` is always a frozen snapshot handed in by the caller, never re-derived. */
final readonly class InvoiceLineData
{
    public function __construct(
        public InvoiceItemType $type,
        public string $description,
        public int $unitPricePaisa,
        public int $quantity = 1,
        public ?int $doctorId = null,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
    ) {}
}
