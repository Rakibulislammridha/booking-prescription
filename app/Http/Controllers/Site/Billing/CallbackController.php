<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Billing;

use App\Domain\Billing\Actions\SettleGatewayPayment;
use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Exceptions\GatewayAmountMismatch;
use App\Domain\Billing\Exceptions\GatewaySignatureInvalid;
use App\Domain\Billing\Gateways\GatewayManager;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The browser's return from the gateway. It renders the outcome for the patient; the AUTHORITATIVE record is
 * written by the same `SettleGatewayPayment` action the webhook uses, and both are idempotent, so whichever
 * arrives first wins and the second is a no-op.
 */
final class CallbackController extends Controller
{
    public function __invoke(Request $request, string $gateway, GatewayManager $gateways, SettleGatewayPayment $settle): RedirectResponse
    {
        $enum = PaymentGateway::tryFrom($gateway);
        abort_if($enum === null, 404);

        $driver = $gateways->configuredDriver($enum);

        try {
            $callback = $driver->verifyCallback($request);
            $result = $settle->handle($driver, $callback);
        } catch (GatewaySignatureInvalid|GatewayAmountMismatch $e) {
            // Never leak why: an attacker probing references learns nothing beyond "it did not work".
            Log::warning('billing.gateway.callback_rejected', ['gateway' => $gateway, 'code' => $e->code()]);

            return redirect()->route('site.home')->with('flash.error', __('billing.flash.payment_failed'));
        }

        $appointmentId = $result->invoice->appointment_id;
        $appointment = $appointmentId === null ? null : Appointment::query()->find($appointmentId);

        if ($appointment === null) {
            return redirect()->route('site.home')->with('flash.success', __('billing.flash.payment_recorded'));
        }

        return redirect()
            ->route('site.booking.confirmed', ['appointment' => $appointment->public_id])
            ->with($result->payment->status->isSettled() ? 'flash.success' : 'flash.error', $result->payment->status->isSettled() ? __('billing.flash.payment_recorded') : __('billing.flash.payment_failed'));
    }
}
