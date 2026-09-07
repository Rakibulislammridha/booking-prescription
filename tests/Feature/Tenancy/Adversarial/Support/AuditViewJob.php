<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial\Support;

use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Branch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Writes one explicit read-audit row from inside a queue job. */
final class AuditViewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static ?int $auditLogId = null;

    public function handle(): void
    {
        self::$auditLogId = AuditLog::view(Branch::query()->firstOrFail(), ['from' => 'job'])->id;
    }
}
