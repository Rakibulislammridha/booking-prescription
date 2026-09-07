<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Contracts\OtpSender;
use App\Domain\Patients\Enums\OtpPurpose;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Log;

/** Development / testing delivery: the code goes to the log (ARCHITECTURE §6.3). */
final class LogOtpSender implements OtpSender
{
    public function send(string $mobile, string $code, OtpPurpose $purpose, string $locale): void
    {
        Log::info('patients.otp.sent', ['tenant_id' => Tenancy::id(), 'mobile' => MobileNumber::mask($mobile), 'purpose' => $purpose->value, 'locale' => $locale, 'code' => $code]);
    }
}
