<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditRecorder::class);
    }
}
