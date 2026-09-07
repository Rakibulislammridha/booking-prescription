<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Data;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Models\Tenant\NotificationTemplate;

/** A template ready to render: either a tenant's `notification_templates` row or a built-in default. */
final readonly class ResolvedTemplate
{
    public function __construct(
        public NotificationEvent $event,
        public NotificationChannel $channel,
        public Locale $locale,
        public string $body,
        public ?string $subject = null,
        public ?string $providerTemplateId = null,
        public ?int $templateId = null,
        public bool $isDefault = true,
    ) {}

    public static function fromModel(NotificationTemplate $template): self
    {
        return new self(
            event: $template->event_key,
            channel: $template->channel,
            locale: $template->locale,
            body: $template->body,
            subject: $template->subject,
            providerTemplateId: $template->provider_template_id,
            templateId: $template->id,
            isDefault: false,
        );
    }
}
