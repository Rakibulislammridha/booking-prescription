<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Notifications;

use App\Domain\Notifications\Services\VapidSigner;
use App\Http\Controllers\Controller;
use App\Http\Resources\Notifications\PushSubscriptionResource;
use App\Models\Tenant\Notification;
use App\Models\Tenant\PushSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which browsers this clinic can reach, and the ability to revoke one that should not be receiving patient alerts.
 * Authorised as `managePush` (`notifications.gateways.manage`), not as the outbound log: revoking a subscription
 * is channel configuration, and the log is readable by the accountant.
 */
final class PushSubscriptionController extends Controller
{
    public function index(Request $request, VapidSigner $vapid): Response
    {
        $this->authorize('managePush', Notification::class);

        return Inertia::render('Notifications/PushSubscriptions', [
            'subscriptions' => PushSubscriptionResource::collection(PushSubscription::query()->orderByDesc('id')->limit(200)->get())->resolve(),
            'vapid_configured' => $vapid->isConfigured(),
        ]);
    }

    public function destroy(Request $request, PushSubscription $subscription): RedirectResponse
    {
        $this->authorize('managePush', Notification::class);
        $subscription->delete();

        return redirect()->route('panel.notifications.push.index')->with('flash.success', __('notifications.flash.push_removed'));
    }
}
