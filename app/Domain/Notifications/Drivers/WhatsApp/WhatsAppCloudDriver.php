<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Drivers\WhatsApp;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Data\DeliveryResult;
use App\Domain\Notifications\Data\GatewayConfig;
use App\Domain\Notifications\Data\OutboundMessage;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Patients\Services\MobileNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp Cloud API (Meta Graph), documented public shape:
 *   POST https://graph.facebook.com/{version}/{phone_number_id}/messages   Authorization: Bearer {access_token}
 *   template: {"messaging_product":"whatsapp","to":"8801…","type":"template",
 *              "template":{"name":…,"language":{"code":"bn"},"components":[{"type":"body","parameters":[{"type":"text","text":…}]}]}}
 *   text:     {"messaging_product":"whatsapp","to":"8801…","type":"text","text":{"preview_url":false,"body":…}}
 *   200:      {"messages":[{"id":"wamid.HBg…"}]}
 *   error:    {"error":{"message":…,"code":131026,"error_subcode":…,"type":"OAuthException"}}
 *
 * A business-initiated message outside the 24-hour customer-service window MUST use an approved template, so the
 * template's `provider_template_id` (the approved template name) drives the request when the tenant has set one;
 * the free-text form is kept for the in-window case and for testing.
 *
 * Bangla needs no special handling here — the API is JSON/UTF-8 — but the approved template's own language code
 * must match, which is why `language.code` follows the notification's locale rather than the tenant default.
 */
final class WhatsAppCloudDriver implements ChannelDriver
{
    private const DEFAULT_BASE = 'https://graph.facebook.com';

    private const DEFAULT_VERSION = 'v21.0';

    /** Meta error codes that repeating cannot fix. */
    private const PERMANENT_CODES = [
        100,      // invalid parameter
        131008,   // required parameter missing
        131009,   // parameter value not valid
        131026,   // message undeliverable (not a WhatsApp number / cannot receive)
        131047,   // re-engagement required outside the 24h window without a template
        131051,   // unsupported message type
        132000, 132001, 132005, 132007, 132012, 132015,   // template does not exist / mismatched / paused / disabled
        190,      // access token expired or revoked
        200, 299, // permission errors
    ];

    /** Rate limiting and transient upstream states. */
    private const TRANSIENT_CODES = [4, 80007, 130429, 131048, 131056, 133016, 1, 2];

    public function __construct(private readonly GatewayConfig $config, private readonly int $timeout = 10) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Whatsapp;
    }

    public function provider(): string
    {
        return 'whatsapp_cloud';
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $phoneNumberId = $this->config->credential('phone_number_id', $this->config->senderId);
        $token = $this->config->credential('access_token') ?? $this->config->credential('token');

        if ($phoneNumberId === null || $token === null) {
            return DeliveryResult::rejected('whatsapp_credentials_missing');
        }

        $base = rtrim((string) $this->config->credential('base_url', self::DEFAULT_BASE), '/');
        $version = (string) $this->config->option('api_version', self::DEFAULT_VERSION);
        $url = "{$base}/{$version}/{$phoneNumberId}/messages";
        $payload = $this->payload($message);
        $started = microtime(true);
        $request = ['url' => $url, 'to' => MobileNumber::mask($message->recipient), 'type' => $payload['type'], 'template' => $message->providerTemplateId];

        try {
            $response = Http::withToken($token)->acceptJson()->timeout($this->timeout)->post($url, $payload);
        } catch (ConnectionException $e) {
            return DeliveryResult::failed('connection', ['message' => $e->getMessage()], $this->elapsed($started))->withRequest($request);
        } catch (Throwable $e) {
            return DeliveryResult::failed('transport', ['message' => $e->getMessage()], $this->elapsed($started))->withRequest($request);
        }

        $latency = $this->elapsed($started);
        $decoded = json_decode($response->body(), true);
        $body = is_array($decoded) ? $decoded : null;

        if ($body === null) {
            return DeliveryResult::failed('malformed_response', ['raw' => mb_substr($response->body(), 0, 500)], $latency)->withRequest($request);
        }

        if ($response->successful() && isset($body['messages'][0]['id'])) {
            return DeliveryResult::sent((string) $body['messages'][0]['id'], $body, $latency)->withRequest($request);
        }

        $code = (int) ($body['error']['code'] ?? 0);
        $label = 'wa_'.($code !== 0 ? $code : $response->status());

        if (in_array($code, self::PERMANENT_CODES, true)) {
            return DeliveryResult::rejected($label, $body, $latency)->withRequest($request);
        }

        if (in_array($code, self::TRANSIENT_CODES, true) || $response->serverError() || $response->status() === 429) {
            return DeliveryResult::failed($label, $body, $latency)->withRequest($request);
        }

        // A 4xx we do not recognise is a request we built wrong: retrying it will build it wrong again.
        return $response->clientError()
            ? DeliveryResult::rejected($label, $body, $latency)->withRequest($request)
            : DeliveryResult::failed($label, $body, $latency)->withRequest($request);
    }

    /** @return array<string, mixed> */
    private function payload(OutboundMessage $message): array
    {
        $to = ltrim(MobileNumber::tryNormalize($message->recipient) ?? $message->recipient, '+');

        if ($message->providerTemplateId === null) {
            return ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => $message->body]];
        }

        $parameters = array_map(fn (string $value): array => ['type' => 'text', 'text' => $value], $message->templateParams());

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $message->providerTemplateId,
                'language' => ['code' => $message->locale === 'bn' ? 'bn' : 'en'],
                'components' => $parameters === [] ? [] : [['type' => 'body', 'parameters' => $parameters]],
            ],
        ];
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
