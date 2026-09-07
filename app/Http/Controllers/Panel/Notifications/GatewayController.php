<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Notifications;

use App\Domain\Notifications\Actions\SaveGateway;
use App\Domain\Notifications\Actions\SendTestMessage;
use App\Domain\Notifications\Enums\GatewayProvider;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Notifications\SaveGatewayRequest;
use App\Http\Requests\Panel\Notifications\TestGatewayRequest;
use App\Http\Resources\Notifications\SmsGatewayResource;
use App\Models\Tenant\SmsGatewaySetting;
use App\Models\Tenant\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Per-tenant gateway credentials, plus the "send test message" action that proves them before a patient depends on them. */
final class GatewayController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SmsGatewaySetting::class);

        return Inertia::render('Notifications/Gateways', [
            'gateways' => SmsGatewayResource::collection(SmsGatewaySetting::query()->orderBy('channel')->orderByDesc('is_default')->orderBy('priority')->get())->resolve(),
            'options' => [
                'channels' => [NotificationChannel::Sms->value, NotificationChannel::Whatsapp->value, NotificationChannel::Ivr->value],
                'providers' => $this->providersByChannel(),
            ],
            'can' => ['manage' => $request->user('web')?->can('create', SmsGatewaySetting::class) ?? false],
        ]);
    }

    public function store(SaveGatewayRequest $request, SaveGateway $save): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user('web');
        $save->handle(null, $request->attributes(), $request->credentials(), $user);

        return redirect()->route('panel.notifications.gateways.index')->with('flash.success', __('notifications.flash.gateway_saved'));
    }

    public function update(SaveGatewayRequest $request, SmsGatewaySetting $gateway, SaveGateway $save): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user('web');
        $save->handle($gateway, $request->attributes(), $request->credentials(), $user);

        return redirect()->route('panel.notifications.gateways.index')->with('flash.success', __('notifications.flash.gateway_saved'));
    }

    public function destroy(Request $request, SmsGatewaySetting $gateway): RedirectResponse
    {
        $this->authorize('delete', $gateway);
        $gateway->delete();

        return redirect()->route('panel.notifications.gateways.index')->with('flash.success', __('notifications.flash.gateway_deleted'));
    }

    public function test(TestGatewayRequest $request, SmsGatewaySetting $gateway, SendTestMessage $send): RedirectResponse
    {
        $result = $send->handle($gateway, (string) $request->input('recipient'), (string) $request->input('body'), Actor::fromRequest($request));

        return $result->isSuccess()
            ? redirect()->route('panel.notifications.gateways.index')->with('flash.success', __('notifications.flash.test_sent'))
            : redirect()->route('panel.notifications.gateways.index')->with('flash.error', __('notifications.flash.test_failed', ['error' => (string) $result->errorCode]));
    }

    /** @return array<string, array<int, string>> */
    private function providersByChannel(): array
    {
        $map = [];

        foreach ([NotificationChannel::Sms, NotificationChannel::Whatsapp, NotificationChannel::Ivr] as $channel) {
            $map[$channel->value] = array_map(fn (GatewayProvider $p) => $p->value, GatewayProvider::forChannel($channel));
        }

        return $map;
    }
}
