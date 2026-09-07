<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Data\NotificationRequest;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Jobs\SendNotificationJob;
use App\Domain\Notifications\Services\ConsentGate;
use App\Domain\Notifications\Services\QuietHours;
use App\Domain\Notifications\Services\RecipientResolver;
use App\Domain\Notifications\Services\SegmentCounter;
use App\Domain\Notifications\Services\TemplateRenderer;
use App\Domain\Notifications\Services\TemplateRepository;
use App\Models\Tenant\Notification;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single entry point every producer uses: render the message for this recipient, decide whether it may be sent
 * at all, write the `notifications` row, and hand it to the queue.
 *
 * Order matters and is deliberate:
 *   1. channel allowed by config              → otherwise nothing is written
 *   2. recipient resolvable                   → a patient with no mobile is not an error, just no SMS
 *   3. consent (ConsentGate)                  → a revoked channel writes nothing and logs the suppression
 *   4. quiet hours (QuietHours)               → non-urgent messages become `scheduled`, never dropped
 *   5. dedupe_key                             → the partial unique index is the guarantee; a duplicate returns null
 *   6. template + render + segment count      → the body is frozen on the row, so a later template edit cannot
 *                                               rewrite history and a retry sends exactly what attempt 1 sent
 *
 * @see SendNotificationJob for what happens after the row exists
 */
final class QueueNotification
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly TemplateRepository $templates,
        private readonly TemplateRenderer $renderer,
        private readonly SegmentCounter $segments,
        private readonly ConsentGate $consent,
        private readonly QuietHours $quietHours,
    ) {}

    /** @return array<int, Notification> the rows written (empty when suppressed or already sent) */
    public function handle(NotificationRequest $request): array
    {
        if (! in_array($request->channel->value, (array) config('notifications.channels', []), true)) {
            return [];
        }

        $addresses = $this->recipients->resolve($request);

        if ($addresses === []) {
            return [];
        }

        if (! $this->consent->allows($request->patient, $request->channel, $request->event)) {
            Log::info('notifications.suppressed.consent', ['tenant_id' => Tenancy::id(), 'event' => $request->event->value, 'channel' => $request->channel->value]);

            return [];
        }

        $locale = $this->recipients->locale($request);
        $template = $this->templates->resolve($request->event, $request->channel, $locale);
        $body = $this->renderer->renderFor($request->channel, $template->body, $request->variables);
        $subject = $template->subject === null ? null : mb_substr($this->renderer->render($template->subject, $request->variables), 0, 160);
        $scheduledFor = $this->scheduleFor($request);
        $rows = [];

        foreach ($addresses as $address) {
            $row = $this->write($request, $address, $locale, $template->templateId, $template->providerTemplateId, $body, $subject, $scheduledFor);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function scheduleFor(NotificationRequest $request): ?CarbonImmutable
    {
        if ($request->scheduledFor !== null) {
            return $request->scheduledFor;
        }

        if ($request->event->isUrgent()) {
            return null;
        }

        $now = CarbonImmutable::now();

        return $this->quietHours->isQuiet($now) ? $this->quietHours->nextOpening($now) : null;
    }

    private function write(
        NotificationRequest $request,
        string $address,
        Locale $locale,
        ?int $templateId,
        ?string $providerTemplateId,
        string $body,
        ?string $subject,
        ?CarbonImmutable $scheduledFor,
    ): ?Notification {
        $dedupeKey = $this->dedupeKey($request, $address);

        if ($dedupeKey !== null && Notification::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $count = $request->channel === NotificationChannel::Sms ? $this->segments->count($body) : null;
        $payload = $request->payload;

        if ($providerTemplateId !== null) {
            $payload['provider_template_id'] = $providerTemplateId;
        }

        $attributes = [
            'event_key' => $request->event,
            'channel' => $request->channel,
            'patient_id' => $request->patient?->id,
            'user_id' => $request->user?->id,
            'notifiable_type' => $request->notifiable?->getMorphClass(),
            'notifiable_id' => $request->notifiable === null ? null : (int) $request->notifiable->getKey(),
            'serial_id' => $request->serialId,
            'notification_template_id' => $templateId,
            'recipient' => mb_substr($address, 0, 255),
            'locale' => $locale,
            'subject' => $subject,
            'body' => $body,
            'payload' => $payload,
            'status' => $scheduledFor === null ? NotificationStatus::Queued : NotificationStatus::Scheduled,
            'scheduled_for' => $scheduledFor,
            'attempts' => 0,
            'dedupe_key' => $dedupeKey,
            'segments' => $count?->segments,
        ];

        try {
            $notification = Notification::query()->create($attributes);
        } catch (QueryException $e) {
            // 23505 = unique_violation: a concurrent producer won the dedupe race. That is the index doing its job.
            if (($e->errorInfo[0] ?? null) === '23505') {
                return null;
            }

            throw $e;
        }

        if ($scheduledFor === null) {
            DB::afterCommit(fn () => SendNotificationJob::dispatch($notification->id)->onQueue('notifications'));
        }

        return $notification;
    }

    /**
     * `<caller key>:<channel>[:<endpoint index>]` — the caller owns the semantic part (`three_ahead:{serial_id}`,
     * `doctor_delayed:{serial_id}:{bucket}`, REALTIME.md §7/§10) and this action makes it unique per channel and
     * per push endpoint so a two-channel fan-out is not mistaken for a duplicate.
     */
    private function dedupeKey(NotificationRequest $request, string $address): ?string
    {
        if ($request->dedupeKey === null) {
            return null;
        }

        $suffix = $request->channel === NotificationChannel::Push ? ':'.substr(hash('sha256', $address), 0, 8) : '';

        return mb_substr($request->dedupeKey.':'.$request->channel->value.$suffix, 0, 120);
    }
}
