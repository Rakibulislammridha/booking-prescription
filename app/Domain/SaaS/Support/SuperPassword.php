<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Support;

use Illuminate\Validation\Rules\Password;

/**
 * The one password rule for the `super` guard, wherever a super password is set: `super:create`, the console's
 * Admins screen, the operator's own Profile and the set-password link. One place, so the four cannot drift — an
 * operator created from the console must not get a weaker password than one created from the shell.
 */
final class SuperPassword
{
    public const MIN_LENGTH = 12;

    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)->letters()->numbers();
    }
}
