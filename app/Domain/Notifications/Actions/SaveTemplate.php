<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Services\TemplateRenderer;
use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\User;

/**
 * Create or replace the tenant's template for (event, channel, locale). Placeholders are validated against the
 * event's documented catalogue BEFORE the row is written, so an unknown `{{invoice_no}}` on a `three_ahead`
 * template is a 422 in the editor rather than an empty gap in a patient's SMS.
 */
final class SaveTemplate
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    public function handle(NotificationEvent $event, NotificationChannel $channel, Locale $locale, string $body, ?string $subject, ?string $providerTemplateId, bool $isActive, ?User $by): NotificationTemplate
    {
        $this->renderer->assertPlaceholdersAllowed($body, $event);

        if ($subject !== null) {
            $this->renderer->assertPlaceholdersAllowed($subject, $event);
        }

        $template = NotificationTemplate::query()->firstOrNew([
            'event_key' => $event->value,
            'channel' => $channel->value,
            'locale' => $locale->value,
        ]);

        $template->fill([
            'body' => $body,
            'subject' => $subject,
            'provider_template_id' => $providerTemplateId,
            'is_active' => $isActive,
            'updated_by_user_id' => $by?->id,
        ])->save();

        return $template;
    }
}
