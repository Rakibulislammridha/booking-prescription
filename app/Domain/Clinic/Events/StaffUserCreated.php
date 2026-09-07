<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Events;

use App\Models\Tenant\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class StaffUserCreated implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly User $user) {}
}
