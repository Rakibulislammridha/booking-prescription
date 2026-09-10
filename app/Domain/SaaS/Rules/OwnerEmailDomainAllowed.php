<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Rules;

use App\Domain\SaaS\Services\PlatformSettings;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `onboarding.allowed_email_domains`: when the platform lists domains, a sign-up's owner email must belong to one
 * of them (a private beta, a franchise rolling out to its own clinics). Empty means any address is welcome.
 */
final class OwnerEmailDomainAllowed implements ValidationRule
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $allowed = self::domains((string) $this->settings->get(PlatformSettingsRegistry::ALLOWED_EMAIL_DOMAINS));

        if ($allowed === [] || ! is_string($value) || ! str_contains($value, '@')) {
            return;
        }

        $domain = mb_strtolower(trim((string) substr($value, (int) strrpos($value, '@') + 1)));

        foreach ($allowed as $candidate) {
            if ($domain === $candidate || str_ends_with($domain, '.'.$candidate)) {
                return;
            }
        }

        $fail(__('saas.onboarding.email_domain_not_allowed', ['domains' => implode(', ', $allowed)]));
    }

    /** @return list<string> lower-cased domains out of a comma/space/newline separated list */
    public static function domains(string $list): array
    {
        $parts = preg_split('/[\s,]+/', mb_strtolower(trim($list))) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $d) => $d !== ''));
    }
}
