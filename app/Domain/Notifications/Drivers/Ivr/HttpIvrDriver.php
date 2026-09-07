<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Drivers\Ivr;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Data\DeliveryResult;
use App\Domain\Notifications\Data\GatewayConfig;
use App\Domain\Notifications\Data\OutboundMessage;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Services\Escaper;
use App\Domain\Patients\Services\MobileNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Outbound IVR voice calls for feature-phone patients (BRIEF §5.J). Bangladeshi voice-call APIs are all the same
 * shape — a POST that starts a call and either speaks a text-to-speech string or plays a pre-recorded flow — so the
 * driver is configurable the way GenericHttpSmsDriver is:
 *
 *   POST {url}  {"to":"8801…","from":"09600…","flow_id":"…","text":"…","language":"bn"}
 *   200: {"call_id":"…","status":"queued"}
 *
 * SAFETY: the spoken text is already escaped for the `speech` context (no XML/SSML metacharacters can survive
 * TemplateRenderer), and every value this driver places into a URL is rawurlencoded here — a patient name is
 * attacker-controlled data and an IVR vendor will happily interpolate it into a TwiML/SSML document.
 */
final class HttpIvrDriver implements ChannelDriver
{
    public function __construct(private readonly GatewayConfig $config, private readonly int $timeout = 15) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Ivr;
    }

    public function provider(): string
    {
        return $this->config->provider->value;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $url = $this->config->credential('url');

        if ($url === null) {
            return DeliveryResult::rejected('ivr_url_missing');
        }

        $to = ltrim(MobileNumber::tryNormalize($message->recipient) ?? $message->recipient, '+');
        $flow = is_string($message->payload['ivr_flow'] ?? null) ? $message->payload['ivr_flow'] : $message->providerTemplateId;

        // The body has been escaped for speech already; escaping again is harmless and keeps the guarantee local.
        $text = Escaper::speech($message->body);

        $payload = array_filter([
            (string) $this->config->option('to_field', 'to') => $to,
            (string) $this->config->option('from_field', 'from') => $this->config->senderId,
            (string) $this->config->option('flow_field', 'flow_id') => $flow,
            (string) $this->config->option('text_field', 'text') => $text,
            (string) $this->config->option('language_field', 'language') => $message->locale,
            'reference' => $message->clientReference(),
        ], fn ($value) => $value !== null && $value !== '');

        /** @var array<string, mixed> $extra */
        $extra = (array) $this->config->option('extra', []);
        $payload = [...$payload, ...$extra];

        $started = microtime(true);
        $request = ['url' => $this->safeUrl($url), 'to' => MobileNumber::mask($message->recipient), 'flow' => $flow, 'text_length' => mb_strlen($text)];

        try {
            $client = Http::acceptJson()->timeout($this->timeout);
            $key = $this->config->credential('api_key') ?? $this->config->credential('token');

            if ($key !== null) {
                $client = $this->config->option('auth', 'bearer') === 'bearer' ? $client->withToken($key) : $client->withHeaders(['X-API-Key' => $key]);
            }

            $response = $client->post($url, $payload);
        } catch (ConnectionException $e) {
            return DeliveryResult::failed('connection', ['message' => $e->getMessage()], $this->elapsed($started))->withRequest($request);
        } catch (Throwable $e) {
            return DeliveryResult::failed('transport', ['message' => $e->getMessage()], $this->elapsed($started))->withRequest($request);
        }

        $latency = $this->elapsed($started);
        $decoded = json_decode($response->body(), true);
        $body = is_array($decoded) ? $decoded : null;

        if ($response->serverError() || $response->status() === 429) {
            return DeliveryResult::failed('http_'.$response->status(), $body ?? ['raw' => mb_substr($response->body(), 0, 500)], $latency)->withRequest($request);
        }

        if ($response->clientError()) {
            return DeliveryResult::rejected('http_'.$response->status(), $body ?? ['raw' => mb_substr($response->body(), 0, 500)], $latency)->withRequest($request);
        }

        if ($body === null) {
            return DeliveryResult::failed('malformed_response', ['raw' => mb_substr($response->body(), 0, 500)], $latency)->withRequest($request);
        }

        $id = Arr::get($body, (string) $this->config->option('call_id_path', 'call_id'));

        return DeliveryResult::sent(is_scalar($id) ? (string) $id : null, $body, $latency)->withRequest($request);
    }

    /** Strip a credential a tenant may have embedded in the URL before it reaches `notification_logs.request`. */
    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return '[url]';
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].($parts['path'] ?? '');
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
