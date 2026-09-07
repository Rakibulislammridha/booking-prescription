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
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SSL Wireless SMS Plus, the most common masking-SMS gateway in Bangladeshi healthcare.
 *
 * Implemented against the vendor's documented public v3 shape:
 *   POST {url}                       (default https://smsplus.sslwireless.com/api/v3/send-sms)
 *   form: api_token, sid, msisdn, sms, csms_id[, sms_type]
 *   200:  {"status":"SUCCESS","status_code":200,"error_message":"",
 *          "smsinfo":[{"sms_status":"SUCCESS","status_message":"...","msisdn":"8801…","sms_type":"UNICODE","reference_id":"…"}]}
 *   fail: {"status":"FAILED"|"ERROR","status_code":…,"error_message":"INVALID_NUMBER"|…}
 *
 * ASSUMPTION (documented in the module notes): the vendor detects Unicode itself, but we still send `sms_type`
 * explicitly when the body is UCS-2 and always compute the segment count locally, because the clinic is billed per
 * segment and must be able to reconcile the invoice against `notifications.segments` without trusting the gateway.
 *
 * `csms_id` is our own ≤20-char reference, unique per attempt, so a retry is never deduplicated into silence by the
 * gateway while still being traceable.
 */
final class SslWirelessSmsDriver implements ChannelDriver
{
    private const DEFAULT_URL = 'https://smsplus.sslwireless.com/api/v3/send-sms';

    /** Refusals that repeating cannot fix — never retried (DeliveryResult::rejected). */
    private const PERMANENT = [
        'INVALID_NUMBER', 'NO_VALID_NUMBER_FOUND', 'INVALID_MSISDN', 'INVALID_API_TOKEN', 'INVALID_SID',
        'MASKING_NOT_ALLOWED', 'SMS_TEXT_EMPTY', 'INVALID_CSMS_ID', 'DUPLICATE_CSMS_ID', 'UNAUTHORIZED',
        'INSUFFICIENT_BALANCE', 'BALANCE_INSUFFICIENT', 'ACCOUNT_INACTIVE',
    ];

    public function __construct(
        private readonly GatewayConfig $config,
        private readonly SegmentCounter $segments,
        private readonly int $timeout = 10,
    ) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Sms;
    }

    public function provider(): string
    {
        return 'ssl_wireless';
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $count = $this->segments->count($message->body);
        $msisdn = ltrim(MobileNumber::tryNormalize($message->recipient) ?? $message->recipient, '+');

        $form = [
            'api_token' => $this->config->credential('api_token', ''),
            'sid' => $this->config->credential('sid', ''),
            'msisdn' => $msisdn,
            'sms' => $message->body,
            'csms_id' => $message->clientReference(),
        ];

        if ($count->isUnicode() && $this->config->unicodeEnabled()) {
            $form['sms_type'] = 'UNICODE';
        }

        $started = microtime(true);
        $request = $this->sanitise($form);

        try {
            $response = Http::asForm()->acceptJson()->timeout($this->timeout)
                ->post($this->config->credential('url', self::DEFAULT_URL) ?? self::DEFAULT_URL, $form);
        } catch (ConnectionException $e) {
            return DeliveryResult::failed('connection', ['message' => $e->getMessage()], $this->elapsed($started))->withRequest($request);
        } catch (Throwable $e) {
            return DeliveryResult::failed('transport', ['message' => $e->getMessage()], $this->elapsed($started))->withRequest($request);
        }

        $latency = $this->elapsed($started);
        $body = $this->decode($response->body());

        if ($response->serverError() || $response->status() === 429) {
            return DeliveryResult::failed('http_'.$response->status(), $body ?? ['raw' => $response->body()], $latency)->withRequest($request);
        }

        if ($body === null) {
            // A 200 with an unparseable body: we do not know whether the SMS went out. Treat as transient — a
            // duplicate is cheaper than a patient who never learns their clinic was cancelled.
            return DeliveryResult::failed('malformed_response', ['raw' => mb_substr($response->body(), 0, 500)], $latency)->withRequest($request);
        }

        $status = strtoupper((string) ($body['status'] ?? ''));
        $error = strtoupper((string) ($body['error_message'] ?? ''));

        if ($status === 'SUCCESS') {
            $reference = $body['smsinfo'][0]['reference_id'] ?? null;

            return DeliveryResult::sent(is_scalar($reference) ? (string) $reference : $message->clientReference(), $body, $latency, $this->cost($count->segments))
                ->withRequest($request);
        }

        if ($response->clientError() && $error === '') {
            return DeliveryResult::rejected('http_'.$response->status(), $body, $latency)->withRequest($request);
        }

        $code = $error !== '' ? $error : ($status !== '' ? $status : 'unknown_error');

        return in_array($code, self::PERMANENT, true)
            ? DeliveryResult::rejected($code, $body, $latency)->withRequest($request)
            : DeliveryResult::failed($code, $body, $latency)->withRequest($request);
    }

    private function cost(int $segments): ?int
    {
        $perSegment = $this->config->option('cost_per_segment_paisa');

        return is_numeric($perSegment) ? $segments * (int) $perSegment : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * `notification_logs.request` must never carry a credential (SCHEMA §3.6 "sanitised outbound payload").
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function sanitise(array $form): array
    {
        return [
            'url' => $this->config->credential('url', self::DEFAULT_URL),
            'msisdn' => MobileNumber::mask((string) $form['msisdn']),
            'csms_id' => $form['csms_id'],
            'sms_type' => $form['sms_type'] ?? 'TEXT',
            'sms_length' => mb_strlen((string) $form['sms']),
        ];
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
