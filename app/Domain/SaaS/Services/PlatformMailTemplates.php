<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Domain\Shared\Money;
use Illuminate\Support\Str;

/**
 * The platform → owner mail copy (welcome, dunning, final notice, suspension), as an operator may have rewritten
 * it in the console. Each subject and body is a platform settings key (`templates.<name>.<field>.<locale>`) whose
 * REGISTRY DEFAULT is the shipped `saas.mail.*` string, so "reset to default" is `PlatformSettings::reset()` and
 * a template nobody touched renders exactly what the language file says. Placeholders are Laravel's `:name`
 * form, replaced the way `__()` replaces them (longest first; `:Name` / `:NAME` follow the placeholder's case).
 */
final class PlatformMailTemplates
{
    public function __construct(private readonly PlatformSettings $settings) {}

    /** `saas.mail.dunning.subject` → `dunning` when the key names an editable template, else null. */
    public static function templateOf(string $langKey): ?string
    {
        if (preg_match('/^saas\.mail\.([a-z_]+)\.(subject|body)$/', $langKey, $m) !== 1) {
            return null;
        }

        return in_array($m[1], PlatformSettingsRegistry::TEMPLATES, true) ? $m[1] : null;
    }

    /**
     * The effective text for a `saas.mail.*` key in a locale, placeholders filled.
     *
     * @param  array<string, string|int|float>  $replace
     */
    public function render(string $langKey, array $replace, string $locale): string
    {
        $template = self::templateOf($langKey);

        if ($template === null) {
            return (string) __($langKey, $replace, $locale);
        }

        $field = str_ends_with($langKey, '.subject') ? 'subject' : 'body';
        $raw = (string) $this->settings->get(PlatformSettingsRegistry::templateKey($template, $field, $locale));

        return self::replace($raw, $replace);
    }

    /** The subject + body of one editable template with sample data, for the console's preview. */
    /** @return array{subject: string, body: string} */
    public function preview(string $template, string $locale, ?string $subject = null, ?string $body = null): array
    {
        $sample = self::sampleVariables($template, $locale);

        return [
            'subject' => self::replace($subject ?? (string) $this->settings->get(PlatformSettingsRegistry::templateKey($template, 'subject', $locale)), $sample),
            'body' => self::replace($body ?? (string) $this->settings->get(PlatformSettingsRegistry::templateKey($template, 'body', $locale)), $sample),
        ];
    }

    /**
     * Sample values for every placeholder a template's listener supplies (PlatformSettingsRegistry::TEMPLATE_PLACEHOLDERS).
     *
     * @return array<string, string|int>
     */
    public static function sampleVariables(string $template, string $locale): array
    {
        $bn = $locale === 'bn';
        $all = [
            'clinic' => $bn ? 'সেবা হাসপাতাল' : 'Sheba Hospital',
            'owner' => $bn ? 'ডা. রহমান' : 'Dr. Rahman',
            'url' => 'https://sheba.'.config('tenancy.central_domain', 'example.test').'/panel',
            'trial_ends' => now()->addDays(14)->toFormattedDateString(),
            'number' => 'INV-2026-000123',
            'amount' => Money::bdt(250000)->format(),
            'due' => now()->subDays(3)->toFormattedDateString(),
            'suspends_on' => now()->addDays(7)->toFormattedDateString(),
            'step' => 2,
        ];

        return array_intersect_key($all, array_flip(PlatformSettingsRegistry::TEMPLATE_PLACEHOLDERS[$template] ?? []));
    }

    /** @param  array<string, string|int|float>  $replace */
    public static function replace(string $line, array $replace): string
    {
        if ($replace === []) {
            return $line;
        }

        uksort($replace, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $shouldReplace = [];

        foreach ($replace as $key => $value) {
            $value = (string) $value;
            $shouldReplace[':'.Str::ucfirst($key)] = Str::ucfirst($value);
            $shouldReplace[':'.Str::upper($key)] = Str::upper($value);
            $shouldReplace[':'.$key] = $value;
        }

        return strtr($line, $shouldReplace);
    }
}
