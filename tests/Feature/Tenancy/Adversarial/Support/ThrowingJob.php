<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

final class ThrowingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        throw new RuntimeException('adversarial: this job fails on purpose');
    }
}
