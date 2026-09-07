<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Shared\Exceptions\DomainException;

/** A template body used a placeholder outside the event's documented catalogue (TemplateRenderer). */
final class UnknownPlaceholder extends DomainException
{
    /**
     * @param  array<int, string>  $unknown
     * @param  array<int, string>  $allowed
     */
    public function __construct(public readonly NotificationEvent $event, public readonly array $unknown, public readonly array $allowed)
    {
        parent::__construct(sprintf('Unknown placeholder(s) for %s: %s. Allowed: %s.', $event->value, implode(', ', $unknown), implode(', ', $allowed)));
    }

    public function code(): string
    {
        return 'notifications.unknown_placeholder';
    }
}
