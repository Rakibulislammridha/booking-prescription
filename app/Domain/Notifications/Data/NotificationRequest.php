<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Data;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Models\Tenant\Patient;
use App\Models\Tenant\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One requested send. Everything a listener knows, before consent, quiet hours, templates and dedupe are applied.
 * `variables` is the placeholder bag for the event's documented catalogue; `payload` carries the channel extras
 * SCHEMA §3.6 names (`link`, `template_params`, `ivr_flow`).
 */
final readonly class NotificationRequest
{
    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public NotificationEvent $event,
        public NotificationChannel $channel,
        public ?Patient $patient = null,
        public ?User $user = null,
        public ?string $recipient = null,
        public ?Locale $locale = null,
        public array $variables = [],
        public array $payload = [],
        public ?Model $notifiable = null,
        public ?int $serialId = null,
        public ?string $dedupeKey = null,
        public ?CarbonImmutable $scheduledFor = null,
    ) {}

    public function withChannel(NotificationChannel $channel): self
    {
        return new self($this->event, $channel, $this->patient, $this->user, $this->recipient, $this->locale, $this->variables, $this->payload, $this->notifiable, $this->serialId, $this->dedupeKey, $this->scheduledFor);
    }

    public function withRecipient(string $recipient, ?string $dedupeKey = null): self
    {
        return new self($this->event, $this->channel, $this->patient, $this->user, $recipient, $this->locale, $this->variables, $this->payload, $this->notifiable, $this->serialId, $dedupeKey ?? $this->dedupeKey, $this->scheduledFor);
    }

    /** @param  array<string, mixed>  $extra */
    public function withPayload(array $extra): self
    {
        return new self($this->event, $this->channel, $this->patient, $this->user, $this->recipient, $this->locale, $this->variables, [...$this->payload, ...$extra], $this->notifiable, $this->serialId, $this->dedupeKey, $this->scheduledFor);
    }
}
