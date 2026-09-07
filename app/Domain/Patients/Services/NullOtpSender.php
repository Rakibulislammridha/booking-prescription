<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Contracts\OtpSender;
use App\Domain\Patients\Enums\OtpPurpose;

/** Silent delivery (tests that only inspect patient_otp_codes rows). */
final class NullOtpSender implements OtpSender
{
    /** @var array<int, array{mobile: string, code: string, purpose: string, locale: string}> */
    public array $sent = [];

    public function send(string $mobile, string $code, OtpPurpose $purpose, string $locale): void
    {
        $this->sent[] = ['mobile' => $mobile, 'code' => $code, 'purpose' => $purpose->value, 'locale' => $locale];
    }
}
