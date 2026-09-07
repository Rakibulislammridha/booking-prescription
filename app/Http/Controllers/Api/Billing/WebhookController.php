<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Billing;

use App\Domain\Billing\Actions\SettleGatewayPayment;
use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Exceptions\GatewayAmountMismatch;
use App\Domain\Billing\Exceptions\GatewayNotConfigured;
use App\Domain\Billing\Exceptions\GatewaySignatureInvalid;
use App\Domain\Billing\Gateways\GatewayManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `POST /api/webhooks/payments/{gateway}` — the gateway's server-to-server notification. CSRF-exempt by
 * `bootstrap/app.php` (`api/webhooks/*`), unauthenticated by nature, and therefore hardened:
 *
 *   · the driver verifies the gateway's signature first (`hash_equals`); an unsigned or wrong-signature body is
 *     a 202 with nothing written, so a prober cannot tell a valid reference from an invalid one;
 *   · the amount is NEVER taken from the request — `SettleGatewayPayment` fetches it server-to-server and
 *     compares it with the amount frozen when the checkout started;
 *   · settlement is idempotent on the payment row and on `(gateway, gateway_txn_id)`, so a re-delivery (which
 *     every gateway does) changes nothing and still answers 200 so the gateway stops retrying.
 */
final class WebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, GatewayManager $gateways, SettleGatewayPayment $settle): JsonResponse
    {
        $enum = PaymentGateway::tryFrom($gateway);

        if ($enum === null) {
            return response()->json(['status' => 'ignored'], 202);
        }

        try {
            $driver = $gateways->configuredDriver($enum);
            $callback = $driver->verifyCallback($request);
            $result = $settle->handle($driver, $callback);
        } catch (GatewaySignatureInvalid|GatewayNotConfigured|GatewayAmountMismatch $e) {
            Log::warning('billing.gateway.webhook_rejected', ['gateway' => $gateway, 'code' => $e->code()]);

            // 202: accepted-and-discarded. A 4xx would make gateways retry a payload we will never accept.
            return response()->json(['status' => 'ignored'], 202);
        }

        return response()->json([
            'status' => $result->duplicate ? 'duplicate' : 'recorded',
            'payment_status' => $result->payment->status->value,
        ]);
    }
}
