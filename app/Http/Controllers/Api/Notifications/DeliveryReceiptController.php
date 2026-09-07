<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Notifications;

use App\Domain\Notifications\Enums\NotificationLogStatus;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Notification;
use App\Models\Tenant\NotificationLog;
use App\Models\Tenant\SmsGatewaySetting;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Gateway delivery receipts (DLR). Providers report the handset outcome minutes after accepting a message, which is
 * the difference between `sent` (the gateway took it) and `delivered` (a phone rang).
 *
 * Authentication is a per-gateway shared secret stored in the row's encrypted `credentials.dlr_secret`, compared in
 * constant time. There is no session here — the caller is a machine on the public internet — so an unknown or
 * mismatched secret is a flat 404: an attacker learns nothing about which gateway ids exist.
 *
 * The receipt is matched on (`provider`, `provider_message_id`), the partial unique index of `notification_logs`.
 */
final class DeliveryReceiptController extends Controller
{
    /** Values a provider may send for "it reached the handset". Anything else is treated as a failure report. */
    private const DELIVERED = ['delivered', 'delivrd', 'success', 'ok', 'dlvrd', '1'];

    public function __invoke(Request $request, SmsGatewaySetting $gateway): JsonResponse
    {
        $secret = $gateway->credentials['dlr_secret'] ?? null;
        $presented = (string) ($request->header('X-Dlr-Secret') ?? $request->input('secret', ''));

        if (! is_string($secret) || $secret === '' || ! hash_equals($secret, $presented)) {
            abort(404);
        }

        $messageId = trim((string) $request->input('message_id', $request->input('reference_id', '')));
        $status = strtolower(trim((string) $request->input('status', '')));

        if ($messageId === '') {
            return response()->json(['message' => 'message_id is required', 'code' => 'notifications.dlr_missing_id'], 422);
        }

        $log = NotificationLog::query()
            ->where('provider', $gateway->provider->value)
            ->where('provider_message_id', $messageId)
            ->orderByDesc('id')
            ->first();

        if ($log === null) {
            Log::info('notifications.dlr.unmatched', ['gateway_id' => $gateway->id, 'provider' => $gateway->provider->value]);

            return response()->json(['matched' => false]);
        }

        $delivered = in_array($status, self::DELIVERED, true);
        $log->forceFill(['status' => $delivered ? NotificationLogStatus::Delivered : NotificationLogStatus::Failed])->save();

        $notification = Notification::query()->find($log->notification_id);

        if ($notification !== null && $notification->status === NotificationStatus::Sent) {
            $notification->forceFill($delivered
                ? ['status' => NotificationStatus::Delivered, 'delivered_at' => CarbonImmutable::now()]
                : ['status' => NotificationStatus::Failed, 'last_error' => mb_substr('dlr_'.($status !== '' ? $status : 'failed'), 0, 255)],
            )->save();
        }

        return response()->json(['matched' => true, 'delivered' => $delivered]);
    }
}
