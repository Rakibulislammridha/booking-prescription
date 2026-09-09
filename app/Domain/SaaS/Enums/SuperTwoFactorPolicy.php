<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

/**
 * The platform-wide second-factor policy for the super console — the value of the `security.super_two_factor`
 * platform setting (SCHEMA §2.19, ARCHITECTURE §6.5). Read at request time through `SuperTwoFactor::policy()`,
 * never at boot, so flipping it in the console takes effect on the next request without a restart.
 *
 *   · REQUIRED — every operator must enrol before the console opens to them, and is challenged at every sign-in.
 *   · OPTIONAL — an operator who has enrolled is challenged; one who has not signs in with the password alone.
 *   · DISABLED — nobody is challenged, enrolled or not. Secrets and recovery codes are KEPT, not wiped, so
 *     switching back restores every enrolment exactly as it was.
 */
enum SuperTwoFactorPolicy: string
{
    case Required = 'required';
    case Optional = 'optional';
    case Disabled = 'disabled';

    /** Un-enrolled operators are held on the enrolment screen. */
    public function forcesEnrolment(): bool
    {
        return $this === self::Required;
    }

    /** Enrolled operators are challenged at sign-in and may manage their factor. */
    public function challengesEnrolled(): bool
    {
        return $this !== self::Disabled;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
