<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Drivers\Sms;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Data\DeliveryResult;
use App\Domain\Notifications\Data\GatewayConfig;
use App\Domain\Notifications\Data\OutboundMessage;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Services\SegmentCounter;
use App\Domain\Patients\Services\MobileNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The configurable HTTP SMS driver — one class for every "GET/POST with an api key, a `to` and a `text`" gateway
 * in Bangladesh (bulksmsbd, the operator aggregators, an aggregator a clinic already has a contract with) and for
 * `custom_http`. Everything that differs between them is a per-tenant `options` value, so onboarding a new gateway
 * is a settings row, not a deployment.
 *
 * credentials: {url, api_key?, username?, password?, token?}
 * options: {
 *   method: 'POST'|'GET',  body_format: 'form'|'json'|'query',
 *   to_field, body_field, sender_field, unicode_field, unicode_value, extra: {…},
 *   auth: 'query'|'bearer'|'basic'|'none',  api_key_field,
 *   success_path, success_values[], message_id_path, error_code_path,
 *   permanent_errors[]
 * }
 *
 * Unicode: when the rendered body is UCS-2 the driver sets the tenant's configured Unicode flag (many BD gateways
 * take `type=unicode`) — a Bangla message sent as plain text arrives as question marks on a feature phone.
 */
final class GenericHttpSmsDriver implements ChannelDriver
{
    /** Defaults that match the majority of BD aggregator shapes. */
    private const DEFAULTS = [
        'method' => 'POST',
        'body_format' => 'form',
        'to_field' => 'to',
        'body_field' => 'message',
        'sender_field' => 'sender_id',
        'unicode_field' => 'type',
        'unicode_value' => 'unicode',
        'auth' => 'query',
        'api_key_field' => 'api_key',
        'success_path' => 'status',
        'success_values' => ['success', 'ok', 'sent', '200', 'true', 'smsstatus_success'],
        'message_id_path' => 'message_id',
        'error_code_path' => 'error_message',
        'permanent_errors' => ['invalid_number', 'invalid_api_key', 'unauthorized', 'insufficient_balance', 'sender_id_not_approved', 'blacklisted'],
    ];

    public function __construct(
        private readonly GatewayConfig $config,
        private readonly SegmentCounter $segments,
        private readonly int $timeout = 10,
        private readonly NotificationChannel $channel = NotificationChannel::Sms,
    ) {}

    /** The same request shape serves a WhatsApp aggregator that is not Meta's Cloud API, hence the parameter. */
    public function channel(): NotificationChannel
    {
        return $this->channel;
    }

    public function provider(): string
    {
        return $this->config->provider->value;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $url = $this->config->credential('url');

        if ($url === null) {
            return DeliveryResult::rejected('gateway_url_missing');
        }

        $count = $this->segments->count($message->body);
        $payload = $this->payload($message, $count->isUnicode());
        $started = microtime(true);
        $request = ['url' => $url, 'method' => $this->opt('method'), 'to' => MobileNumber::mask($message->recipient), 'sms_length' => mb_strlen($message->body), 'unicode' => $count->isUnicode()];

        try {
            $response = $this->client()->send(
                strtoupper((string) $this->opt('method')),
                $url,
                $this->requestOptions($payload),
            );
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

        if ($this->isSuccess($body)) {
            $id = Arr::get($body, (string) $this->opt('message_id_path'));

            return DeliveryResult::sent(is_scalar($id) ? (string) $id : $message->clientReference(), $body, $latency, $this->cost($count->segments))->withRequest($request);
        }

        $code = Arr::get($body, (string) $this->opt('error_code_path'));
        $code = is_scalar($code) && (string) $code !== '' ? (string) $code : 'gateway_rejected';

        /** @var array<int, string> $permanent */
        $permanent = (array) $this->opt('permanent_errors');

        return in_array(strtolower($code), array_map('strtolower', $permanent), true)
            ? DeliveryResult::rejected($code, $body, $latency)->withRequest($request)
            : DeliveryResult::failed($code, $body, $latency)->withRequest($request);
    }

    /** @return array<string, mixed> */
    private function payload(OutboundMessage $message, bool $unicode): array
    {
        $payload = [
            (string) $this->opt('to_field') => ltrim(MobileNumber::tryNormalize($message->recipient) ?? $message->recipient, '+'),
            (string) $this->opt('body_field') => $message->body,
        ];

        if ($this->config->senderId !== null && (string) $this->opt('sender_field') !== '') {
            $payload[(string) $this->opt('sender_field')] = $this->config->senderId;
        }

        if ($unicode && $this->config->unicodeEnabled() && (string) $this->opt('unicode_field') !== '') {
            $payload[(string) $this->opt('unicode_field')] = (string) $this->opt('unicode_value');
        }

        if ($this->opt('auth') === 'query') {
            $key = $this->config->credential('api_key') ?? $this->config->credential('token');

            if ($key !== null) {
                $payload[(string) $this->opt('api_key_field')] = $key;
            }

            $username = $this->config->credential('username');
            $password = $this->config->credential('password');

            if ($username !== null) {
                $payload['username'] = $username;
            }

            if ($password !== null) {
                $payload['password'] = $password;
            }
        }

        /** @var array<string, mixed> $extra */
        $extra = (array) $this->opt('extra', []);

        return [...$payload, ...$extra];
    }

    private function client(): PendingRequest
    {
        $client = Http::acceptJson()->timeout($this->timeout);

        return match ($this->opt('auth')) {
            'bearer' => $client->withToken((string) ($this->config->credential('token') ?? $this->config->credential('api_key'))),
            'basic' => $client->withBasicAuth((string) $this->config->credential('username'), (string) $this->config->credential('password')),
            default => $client,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function requestOptions(array $payload): array
    {
        $format = strtoupper((string) $this->opt('method')) === 'GET' ? 'query' : (string) $this->opt('body_format');

        return match ($format) {
            'json' => ['json' => $payload],
            'query' => ['query' => $payload],
            default => ['form_params' => $payload],
        };
    }

    /** @param  array<string, mixed>  $body */
    private function isSuccess(array $body): bool
    {
        $value = Arr::get($body, (string) $this->opt('success_path'));
        /** @var array<int, string> $accepted */
        $accepted = (array) $this->opt('success_values');

        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) && in_array(strtolower((string) $value), array_map('strtolower', $accepted), true);
    }

    private function cost(int $segments): ?int
    {
        $perSegment = $this->config->option('cost_per_segment_paisa');

        return is_numeric($perSegment) ? $segments * (int) $perSegment : null;
    }

    private function opt(string $key, mixed $default = null): mixed
    {
        return $this->config->option($key, $default ?? self::DEFAULTS[$key] ?? null);
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
