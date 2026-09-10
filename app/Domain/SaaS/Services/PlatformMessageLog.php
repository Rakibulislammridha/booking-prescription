<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\Notifications\Drivers\LogChannelDriver;
use App\Models\Central\PlatformMessage;
use Carbon\CarbonImmutable;

/**
 * The platform's outbound ledger (`public.platform_messages`). The Notifications module records what a CLINIC
 * sends to its patients, per tenant schema; nothing central ever saw a dunning mail or a welcome mail leave. One
 * row per attempt, recipient masked, body never stored — enough for "did the final notice reach this owner".
 */
final class PlatformMessageLog
{
    public function record(
        string $channel,
        string $kind,
        string $recipient,
        ?string $subject,
        string $locale,
        string $status,
        ?string $provider = null,
        ?string $error = null,
        ?int $tenantId = null,
        ?int $superAdminId = null,
    ): PlatformMessage {
        return PlatformMessage::query()->create([
            'tenant_id' => $tenantId,
            'channel' => $channel,
            'kind' => $kind,
            'recipient' => LogChannelDriver::mask($recipient),
            'subject' => $subject === null ? null : mb_substr($subject, 0, 255),
            'locale' => substr($locale, 0, 2),
            'status' => $status,
            'provider' => $provider,
            'error' => $error === null ? null : mb_substr($error, 0, 2000),
            'sent_by_super_admin_id' => $superAdminId,
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
