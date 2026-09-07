<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Contracts\OtpSender;
use App\Domain\Patients\Enums\OtpChannel;
use App\Domain\Patients\Enums\OtpPurpose;
use App\Domain\Patients\Exceptions\OtpAttemptsExceeded;
use App\Domain\Patients\Exceptions\OtpExpired;
use App\Domain\Patients\Exceptions\OtpInvalid;
use App\Domain\Patients\Exceptions\OtpThrottled;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientOtpCode;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Hash;

/**
 * ARCHITECTURE §6.3: 6-digit code, 300 s TTL, 60 s resend throttle (cache key otp:{tenantId}:{mobile}), 5 verify
 * attempts; the hash lives in patient_otp_codes (SCHEMA §3.2). Delivery goes through OtpSender. In local/testing
 * the code is fixed to config('patients.otp.fixed_code') (OTP_FIXED_CODE) when set.
 */
final class OtpService
{
    public function __construct(
        private readonly OtpSender $sender,
        private readonly Cache $cache,
    ) {}

    /**
     * Issue a code for $mobile (any format; normalised). The mobile need not belong to a patient yet — booking and
     * mobile verification use it before the patient row exists.
     *
     * @throws OtpThrottled
     */
    public function request(string $mobile, OtpPurpose $purpose = OtpPurpose::Login, ?string $ip = null, OtpChannel $channel = OtpChannel::Sms, ?string $locale = null): PatientOtpCode
    {
        $mobile = MobileNumber::normalize($mobile);
        $key = $this->throttleKey($mobile);

        if ($this->cache->has($key)) {
            $until = $this->cache->get($key);
            $seconds = $until instanceof CarbonImmutable ? max(1, (int) now()->diffInSeconds($until, false)) : $this->resendSeconds();

            throw new OtpThrottled(__('patients.otp.wait_seconds', ['seconds' => $seconds]));
        }

        $code = $this->generateCode();
        $now = CarbonImmutable::now();

        $otp = PatientOtpCode::query()->create([
            'mobile' => $mobile,
            'patient_id' => Patient::query()->household($mobile)->value('id'),
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'channel' => $channel,
            'attempts' => 0,
            'expires_at' => $now->addSeconds($this->ttlSeconds()),
            'ip' => $ip,
        ]);

        $this->cache->put($key, $now->addSeconds($this->resendSeconds()), $this->resendSeconds());
        $this->sender->send($mobile, $code, $purpose, $locale ?? (string) app()->getLocale());

        return $otp;
    }

    /**
     * Check $code against the latest open code for (mobile, purpose); marks it consumed on success.
     *
     * @throws OtpInvalid|OtpExpired|OtpAttemptsExceeded
     */
    public function verify(string $mobile, string $code, OtpPurpose $purpose = OtpPurpose::Login): PatientOtpCode
    {
        $mobile = MobileNumber::normalize($mobile);

        $otp = PatientOtpCode::query()
            ->where('mobile', $mobile)
            ->where('purpose', $purpose->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($otp === null || $otp->isConsumed()) {
            throw new OtpInvalid;
        }

        if ($otp->isExpired()) {
            throw new OtpExpired;
        }

        if ($otp->attemptsExhausted()) {
            throw new OtpAttemptsExceeded;
        }

        $otp->attempts++;
        $otp->save();

        if (! Hash::check(preg_replace('/\D/', '', $code) ?? '', $otp->code_hash)) {
            throw $otp->attemptsExhausted() ? new OtpAttemptsExceeded : new OtpInvalid;
        }

        $otp->consumed_at = CarbonImmutable::now();
        $otp->save();
        $this->cache->forget($this->throttleKey($mobile));

        return $otp;
    }

    /** Seconds until $mobile may request again (0 when not throttled). */
    public function resendAvailableIn(string $mobile): int
    {
        $until = $this->cache->get($this->throttleKey(MobileNumber::normalize($mobile)));

        return $until instanceof CarbonImmutable ? max(0, (int) now()->diffInSeconds($until, false)) : 0;
    }

    /** Rows older than 24 h are pruned (SCHEMA §3.2). */
    public function prune(): int
    {
        return PatientOtpCode::query()->where('created_at', '<', CarbonImmutable::now()->subDay())->delete();
    }

    public function ttlSeconds(): int
    {
        return (int) config('patients.otp.ttl_seconds', 300);
    }

    public function resendSeconds(): int
    {
        return (int) config('patients.otp.resend_seconds', 60);
    }

    private function generateCode(): string
    {
        $fixed = (string) config('patients.otp.fixed_code', '');

        if ($fixed !== '' && app()->environment(['local', 'testing'])) {
            return $fixed;
        }

        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function throttleKey(string $mobile): string
    {
        return 'otp:'.Tenancy::id().':'.$mobile;
    }
}
