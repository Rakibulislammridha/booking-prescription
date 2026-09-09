<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS\Concerns;

use App\Domain\SaaS\Enums\SuperTwoFactorPolicy;
use App\Domain\SaaS\Services\PlatformSettings;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;

/**
 * Set the platform-wide super two-factor policy the way the console does — through the service, which is also
 * what invalidates the cache — so a test states the policy it runs under instead of inheriting whatever
 * `SUPER_2FA_REQUIRED` happens to be in the developer's `.env` (which the testing environment reads).
 */
trait ControlsPlatformSettings
{
    protected function setSuperTwoFactorPolicy(SuperTwoFactorPolicy $policy): void
    {
        app(PlatformSettings::class)->set(PlatformSettingsRegistry::SUPER_TWO_FACTOR, $policy->value);
    }
}
