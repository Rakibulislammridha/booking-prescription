<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial;

use App\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

/** Attack surface 5: migration repositories and DDL under the tenant-only search path. */
final class MigrationIsolationTest extends TestCase
{
    /**
     * Guarantee: tenants:rollback touches only the selected tenant's schema and repository. (Note: `--step=N` is
     * Laravel's meaning — the last N *migrations*, not batches as the command's help text says.)
     */
    public function test_tenants_rollback_only_touches_the_selected_tenant_and_never_public_migrations(): void
    {
        $files = self::tenantMigrationNames();
        $last = end($files);
        $publicBefore = DB::table('public.migrations')->count();

        $this->artisan('tenants:rollback', ['--tenant' => ['9001'], '--step' => 1])->assertSuccessful();

        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
        $this->assertSame($publicBefore, DB::table('public.migrations')->count());

        $this->asTenant('b');
        $this->assertSame($files, DB::table('migrations')->orderBy('migration')->pluck('migration')->all());
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertSame(1, DB::table('branches')->count());

        $this->asTenant('a');
        $ran = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
        $this->assertSame(array_slice($files, 0, -1), $ran, 'exactly the last tenant migration was rolled back');
        $this->assertNotContains($last, $ran);
        $this->assertTrue(Schema::hasTable('branches'));
        $this->assertSame(1, DB::table('branches')->count());
    }

    /** @return array<int, string> migration names on disk, in run order */
    private static function tenantMigrationNames(): array
    {
        $files = array_map(fn (string $f) => basename($f, '.php'), glob(database_path('migrations/tenant/*.php')) ?: []);
        sort($files);

        return $files;
    }

    /** Guarantee: central and tenant repositories never record each other's files. */
    public function test_migration_repositories_do_not_cross(): void
    {
        $central = DB::table('public.migrations')->pluck('migration')->all();
        $this->assertNotEmpty($central);
        $this->assertSame([], array_values(array_filter($central, fn (string $m) => str_starts_with($m, '2026_02_'))));

        foreach (['a', 'b'] as $which) {
            $this->asTenant($which);
            $tenant = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
            $this->assertSame(self::tenantMigrationNames(), $tenant);
            $this->assertSame([], array_values(array_filter($tenant, fn (string $m) => str_starts_with($m, '2026_01_'))));
        }
    }

    /**
     * EXPECTED TO FAIL until fixed (high): `php artisan migrate` run while a tenant is active (e.g. via
     * `tenants:run migrate`, or any command that calls the migrator inside Tenancy::run) reads the TENANT's
     * migrations table (current_schema()), finds none of the central files there, and creates every central table
     * inside tenant_<id> — recording them in the tenant repository. Fix: refuse in a MigrationsStarted listener when
     * Tenancy::check() and the migrator is not the TenantMigrator (or blocklist migrate* in tenants:run).
     */
    public function test_central_migrate_refuses_to_run_inside_a_tenant_context(): void
    {
        Tenancy::initialize($this->tenant('a'));

        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable) {
            // refusing loudly is acceptable
        }

        $created = DB::table('pg_tables')->where('schemaname', self::TENANT_A)
            ->whereIn('tablename', ['tenants', 'plans', 'domains', 'failed_jobs', 'feature_flags'])->orderBy('tablename')->pluck('tablename')->all();
        $this->assertSame([], $created, 'php artisan migrate inside a tenant created central tables in '.self::TENANT_A.': '.implode(', ', $created));
        $this->assertSame(count(self::tenantMigrationNames()), DB::table('migrations')->count(), 'central migrations were recorded in the tenant repository');
    }

    /**
     * Guarantee (corrects ARCHITECTURE §4 "operator-class lookup ignores search_path"): only DEFAULT operator classes
     * (btree_gist exclusion constraints) resolve without qualification. Named extension opclasses and functions
     * installed in public (pg_trgm's gin_trgm_ops, unaccent, ...) must be written `public.<name>` in tenant
     * migrations, otherwise tenants:migrate fails under the tenant-only search path.
     */
    public function test_extension_operator_classes_must_be_schema_qualified_under_the_tenant_search_path(): void
    {
        $this->asTenant('a');

        $this->assertThrows(
            fn () => DB::transaction(fn () => DB::statement('create index adversarial_trgm on branches using gin (name gin_trgm_ops)')),
            QueryException::class,
        );
        DB::transaction(fn () => DB::statement('create index adversarial_trgm on branches using gin (name public.gin_trgm_ops)'));
        $this->assertThrows(fn () => DB::transaction(fn () => DB::scalar("select similarity('a', 'b')")), QueryException::class);
        $this->assertIsNumeric(DB::scalar("select public.similarity('a', 'b')"));

        // default opclass from btree_gist: found without qualification (this is what §4 actually verified)
        DB::transaction(fn () => DB::statement('create table adversarial_excl (doctor_id int, during tstzrange, exclude using gist (doctor_id with =, during with &&))'));
        $this->assertTrue(Schema::hasTable('adversarial_excl'));
    }
}
