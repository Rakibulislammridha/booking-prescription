<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Exceptions\UnknownPlaceholder;

/**
 * The placeholder language: `{{variable}}` (optional inner whitespace), lowercase snake_case names only, ONE pass —
 * a value that itself contains `{{…}}` is never re-expanded, so a patient called "{{link}}" cannot smuggle a URL
 * into an SMS. Unknown names are a save-time validation error (they surface in the preview, not in a patient's
 * phone); a known name with no value renders empty.
 *
 * Values are escaped for the context the body lands in (NotificationChannel::escaping()): HTML entities for email,
 * SSML/XML metacharacters stripped for IVR speech, control characters stripped everywhere. A driver that puts a
 * value into a URL calls Escaper::url() at that point — the message body is never trusted as a URL.
 */
final class TemplateRenderer
{
    public const PATTERN = '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/';

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function render(string $template, array $variables, string $escaping = 'text'): string
    {
        $rendered = preg_replace_callback(
            self::PATTERN,
            function (array $match) use ($variables, $escaping): string {
                $value = $variables[$match[1]] ?? '';

                return Escaper::for($escaping, (string) $value);
            },
            $template,
        );

        return Escaper::stripControl($rendered ?? $template);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function renderFor(NotificationChannel $channel, string $template, array $variables): string
    {
        return $this->render($template, $variables, $channel->escaping());
    }

    /** @return array<int, string> the placeholder names used by a template body, in order of first appearance */
    public function placeholders(string $template): array
    {
        preg_match_all(self::PATTERN, $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Save-time validation: every placeholder must be in the event's documented catalogue.
     *
     * @throws UnknownPlaceholder
     */
    public function assertPlaceholdersAllowed(string $template, NotificationEvent $event): void
    {
        $allowed = $event->variables();
        $unknown = array_values(array_diff($this->placeholders($template), $allowed));

        if ($unknown !== []) {
            throw new UnknownPlaceholder($event, $unknown, $allowed);
        }
    }
}
