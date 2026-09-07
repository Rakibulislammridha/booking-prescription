<?php

declare(strict_types=1);

namespace App\Domain\Patients\Contracts;

use App\Domain\Patients\Enums\OtpPurpose;

/**
 * Delivers a one-time code. Bound to LogOtpSender until the Notifications module binds its SMS channel
 * (`$this->app->bind(OtpSender::class, SmsOtpSender::class)` in NotificationsServiceProvider).
 */
interface OtpSender
{
    public function send(string $mobile, string $code, OtpPurpose $purpose, string $locale): void;
}
