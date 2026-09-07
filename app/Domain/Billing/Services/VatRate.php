<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Clinic\Services\Settings;

/** `billing.vat_percent` (SCHEMA Appendix B) as integer basis points — a percentage never reaches the arithmetic. */
final class VatRate
{
    public const SETTING = 'billing.vat_percent';

    public function __construct(private readonly Settings $settings) {}

    public function basisPoints(): int
    {
        /** @var int|float|string $percent */
        $percent = $this->settings->get(self::SETTING);

        return max(0, min(1000000, Paisa::percentToBasisPoints(is_string($percent) ? $percent : (is_int($percent) ? $percent : (float) $percent))));
    }

    /** Display value for the invoice header ("VAT 7.5%"). */
    public function percentLabel(): string
    {
        return rtrim(rtrim(Paisa::toDecimal($this->basisPoints()), '0'), '.');
    }
}
