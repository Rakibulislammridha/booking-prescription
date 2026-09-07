<?php

declare(strict_types=1);

namespace App\Domain\Booking\Data;

use App\Domain\Booking\Enums\FeeRule;

/** Staff fee override (SCHEMA §5.10 last row): the fee-override permission is checked by the caller. */
final readonly class FeeOverride
{
    public function __construct(
        public int $feePaisa,
        public FeeRule $rule,          // manual | waived
        public ?string $reason = null,
    ) {}
}
