<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Events;

use App\Models\Tenant\Branch;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class BranchCreated implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Branch $branch) {}
}
