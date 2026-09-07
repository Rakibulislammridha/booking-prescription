<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Data\ResolvedTemplate;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Models\Tenant\NotificationTemplate;

/**
 * Template lookup for one tenant: an ACTIVE row for (event, channel, locale) wins, otherwise the built-in default.
 * The per-request memo keeps a 200-patient fan-out from re-querying the same row 200 times; it is per-instance and
 * the service is not a singleton, so nothing leaks between tenants or Octane requests.
 */
final class TemplateRepository
{
    /** @var array<string, ResolvedTemplate> */
    private array $memo = [];

    public function __construct(private readonly DefaultTemplates $defaults) {}

    public function resolve(NotificationEvent $event, NotificationChannel $channel, Locale $locale): ResolvedTemplate
    {
        $key = "{$event->value}|{$channel->value}|{$locale->value}";

        if (! isset($this->memo[$key])) {
            $row = NotificationTemplate::query()
                ->where('event_key', $event->value)
                ->where('channel', $channel->value)
                ->where('locale', $locale->value)
                ->where('is_active', true)
                ->first();

            $this->memo[$key] = $row === null ? $this->defaults->for($event, $channel, $locale) : ResolvedTemplate::fromModel($row);
        }

        return $this->memo[$key];
    }

    public function forget(): void
    {
        $this->memo = [];
    }
}
