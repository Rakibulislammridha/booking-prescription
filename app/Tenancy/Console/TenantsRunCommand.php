<?php

declare(strict_types=1);

namespace App\Tenancy\Console;

use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Scheduler fan-out helper: tenants:run queue:refresh-eta --option=days=14 (ARCHITECTURE §4.7).
 */
final class TenantsRunCommand extends Command
{
    use ResolvesTenants;

    protected $signature = 'tenants:run {artisan : The artisan command to run per tenant} {--tenant=* : ids or slugs} {--option=* : key=value options forwarded as --key=value}';

    protected $description = 'Run an artisan command inside every (or the selected) tenant context';

    public function handle(): int
    {
        $command = (string) $this->argument('artisan');   // 'command' is reserved by Symfony Console
        $options = [];

        foreach ((array) $this->option('option') as $pair) {
            [$key, $value] = array_pad(explode('=', (string) $pair, 2), 2, true);
            $options['--'.ltrim((string) $key, '-')] = $value;
        }

        $failed = 0;

        foreach ($this->resolveTenants() as $tenant) {
            try {
                $exit = Tenancy::run($tenant, fn () => Artisan::call($command, $options, $this->output));

                if ($exit !== 0) {
                    $failed++;
                    $this->components->error("Tenant #{$tenant->id} [{$tenant->slug}]: {$command} exited with {$exit}");
                }
            } catch (Throwable $e) {
                $failed++;
                $this->components->error("Tenant #{$tenant->id} [{$tenant->slug}]: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
