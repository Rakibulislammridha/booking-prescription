<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Data\ResolvedTemplate;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;

/**
 * The built-in message bodies every tenant starts with. They live in `resources/lang/{en,bn}.json` under
 * `notifications.defaults.*` and are read with an explicit locale — the recipient's locale, never the request's —
 * so a Bangla patient gets Bangla while an English-preferring one gets English in the same fan-out.
 *
 * A tenant row in `notification_templates` overrides one of these; there is no state where a clinic that never
 * opened the templates screen sends nothing.
 */
final class DefaultTemplates
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    public function for(NotificationEvent $event, NotificationChannel $channel, Locale $locale): ResolvedTemplate
    {
        $body = $channel === NotificationChannel::Ivr
            ? $this->ivrBody($event, $locale)
            : $this->line("notifications.defaults.{$event->value}.body", $locale);

        $subject = in_array($channel, [NotificationChannel::Email, NotificationChannel::Push], true)
            ? $this->line("notifications.defaults.{$event->value}.subject", $locale)
            : null;

        return new ResolvedTemplate($event, $channel, $locale, $body, $subject, null, null, true);
    }

    /**
     * IVR uses its own line where one exists; otherwise the text body with URLs dropped — reading a verification
     * URL aloud to a feature phone is noise, and it is the one placeholder that never belongs in speech.
     */
    private function ivrBody(NotificationEvent $event, Locale $locale): string
    {
        $key = "notifications.defaults.{$event->value}.ivr";
        $line = $this->line($key, $locale);

        if ($line !== $key) {
            return $line;
        }

        $body = $this->line("notifications.defaults.{$event->value}.body", $locale);

        return trim((string) preg_replace('/\s*\{\{\s*link\s*\}\}\s*/', ' ', $body));
    }

    private function line(string $key, Locale $locale): string
    {
        $line = __($key, [], $locale->value);

        return is_string($line) ? $line : $key;
    }

    /**
     * The variable catalogue shown next to the editor: every placeholder the event allows, with a sample value the
     * preview endpoint renders with.
     *
     * @return array<string, string>
     */
    public function sampleVariables(NotificationEvent $event, Locale $locale): array
    {
        $bn = $locale === Locale::Bn;

        $all = [
            'clinic' => $bn ? 'সেবা হাসপাতাল' : 'Sheba Hospital',
            'patient_name' => $bn ? 'রহিমা খাতুন' : 'Rahima Khatun',
            'link' => 'https://demo.example/rx/01JABCDEFGHJKMNPQRSTVWXYZ',
            'serial' => 'A-012',
            'new_serial' => 'B-004',
            'doctor' => $bn ? 'ডা. রহমান' : 'Dr. Rahman',
            'new_doctor' => $bn ? 'ডা. করিম' : 'Dr. Karim',
            'branch' => $bn ? 'প্রধান শাখা' : 'Main branch',
            'date' => $bn ? '১২ মার্চ' : '12 March',
            'time' => $bn ? 'সকাল ১০টা' : '10:00 am',
            'ahead' => $bn ? '৩' : '3',
            'eta' => $bn ? 'সকাল ১০:২০' : '10:20 am',
            'delay_minutes' => $bn ? '৪০' : '40',
            'expected_start_time' => $bn ? 'সকাল ১০:৪০' : '10:40 am',
            'reason' => $bn ? 'জরুরি ছুটি' : 'Emergency leave',
            'code' => '123456',
            'minutes' => '5',
            'amount' => $bn ? '৳৫০০.০০' : '৳500.00',
            'invoice_no' => 'INV-000042',
        ];

        return array_intersect_key($all, array_flip($event->variables()));
    }

    /** Render a template body with the sample set — the preview endpoint and the panel's live counter. */
    public function preview(NotificationEvent $event, NotificationChannel $channel, Locale $locale, string $body): string
    {
        return $this->renderer->renderFor($channel, $body, $this->sampleVariables($event, $locale));
    }
}
