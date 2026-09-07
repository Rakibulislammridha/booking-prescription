<?php

declare(strict_types=1);

namespace App\Tenancy\Console;

use App\Domain\Tenancy\Actions\ProvisionTenant;
use App\Domain\Tenancy\Data\ProvisionTenantData;
use App\Models\Central\Plan;
use Database\Seeders\Central\PlansSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class TenantsCreateCommand extends Command
{
    protected $signature = 'tenants:create {name : Clinic name}
        {--slug= : URL slug (defaults to a slug of the name)}
        {--plan=starter : Plan code}
        {--domain= : Additional custom domain (pending verification)}
        {--admin-email= : Hospital admin login email}
        {--admin-password= : Hospital admin password (generated when omitted)}
        {--owner-name= : Primary contact name}
        {--owner-mobile= : Primary contact mobile (E.164)}
        {--demo : Seed demo data (doctors, schedules, patients)}';

    protected $description = 'Provision a new tenant: public rows, schema, migrations, roles, first branch and admin user';

    public function handle(ProvisionTenant $provision): int
    {
        $name = (string) $this->argument('name');
        $slug = (string) ($this->option('slug') ?: Str::slug($name));
        $password = (string) ($this->option('admin-password') ?: Str::password(16));

        if (! Plan::query()->exists()) {                                   // fresh install: seed the plan catalogue first
            $this->components->info('No plans found; seeding the default plans.');
            $this->laravel->make(PlansSeeder::class)->setContainer($this->laravel)->setCommand($this)->__invoke();
        }

        $tenant = $provision->handle(new ProvisionTenantData(
            name: $name,
            slug: $slug,
            planCode: (string) $this->option('plan'),
            ownerName: (string) ($this->option('owner-name') ?: $name),
            ownerEmail: (string) ($this->option('admin-email') ?: "admin@{$slug}.test"),
            ownerMobile: (string) ($this->option('owner-mobile') ?: '+8801700000000'),
            adminEmail: (string) ($this->option('admin-email') ?: "admin@{$slug}.test"),
            adminPassword: $password,
            customDomain: $this->option('domain') ? (string) $this->option('domain') : null,
            demo: (bool) $this->option('demo'),
        ));

        $host = $tenant->slug.'.'.config('tenancy.central_domain');
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'http';

        $this->components->info("Tenant #{$tenant->id} [{$tenant->slug}] provisioned in schema {$tenant->schema_name}.");
        $this->components->twoColumnDetail('Panel', "{$scheme}://{$host}".($port ? ":{$port}" : '').'/panel');
        $this->components->twoColumnDetail('Admin login', (string) ($this->option('admin-email') ?: "admin@{$slug}.test"));

        if (! $this->option('admin-password')) {
            $this->components->twoColumnDetail('Admin password', $password);
        }

        return self::SUCCESS;
    }
}
