<?php

declare(strict_types=1);

namespace App\Console\Commands\Patients;

use App\Domain\Patients\Services\OtpService;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;

/**
 * patients:prune-otp — deletes patient_otp_codes older than a day (OtpService::prune). Tenant-scoped: run it through
 * `tenants:run patients:prune-otp` (scheduled daily in routes/console.php, ARCHITECTURE §4.7).
 */
final class PruneOtpCommand extends Command
{
    protected $signature = 'patients:prune-otp';

    protected $description = 'Delete expired patient OTP codes for the active tenant (use via tenants:run)';

    public function handle(OtpService $otp): int
    {
        if (! Tenancy::check()) {
            $this->components->error('patients:prune-otp is tenant-scoped: run it as `tenants:run patients:prune-otp`.');

            return self::FAILURE;
        }

        $deleted = $otp->prune();
        $this->components->info(sprintf('Tenant #%d: pruned %d expired OTP code(s).', (int) Tenancy::id(), $deleted));

        return self::SUCCESS;
    }
}
