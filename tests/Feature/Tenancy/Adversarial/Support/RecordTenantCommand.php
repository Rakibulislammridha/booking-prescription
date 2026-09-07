<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial\Support;

use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Records the tenancy it was invoked under (for tenants:run fan-out tests). */
final class RecordTenantCommand extends Command
{
    protected $signature = 'adversarial:record-tenant';

    protected $description = 'Test-only: records Tenancy::id() and the live search_path';

    /** @var array<int, array{tenant_id: int|null, search_path: string}> */
    public static array $seen = [];

    public function handle(): int
    {
        self::$seen[] = ['tenant_id' => Tenancy::id(), 'search_path' => (string) DB::scalar('show search_path')];

        return self::SUCCESS;
    }
}
