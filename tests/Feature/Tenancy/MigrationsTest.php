<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MigrationsTest extends TestCase
{
    private const CENTRAL_TABLES = [
        'tenants', 'plans', 'plan_features', 'subscriptions', 'super_admins', 'domains', 'subscription_invoices', 'subscription_payments',
        'feature_flags', 'usage_counters', 'tenant_backups', 'catalog_reconciliation_reports', 'custom_brand_promotions', 'impersonation_tokens',
        'audit_logs_central', 'personal_access_tokens', 'password_reset_tokens', 'failed_jobs', 'job_batches', 'migrations',
    ];

    private const TENANT_TABLES = [
        'branches', 'departments', 'specialties', 'users', 'password_reset_tokens', 'personal_access_tokens', 'permissions', 'roles',
        'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'doctors', 'doctor_profiles', 'doctor_specialties',
        'doctor_pad_settings', 'holidays', 'doctor_leaves', 'settings', 'audit_logs', 'migrations',
    ];

    public function test_central_migrations_created_every_public_table_and_the_extensions(): void
    {
        foreach (self::CENTRAL_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "public.{$table} missing");
        }

        $this->assertSame(['btree_gist', 'pg_trgm'], DB::table('pg_extension')->whereIn('extname', ['btree_gist', 'pg_trgm'])->orderBy('extname')->pluck('extname')->all());
        $this->assertTrue((bool) DB::scalar("select exists (select 1 from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public' and p.proname = 'fn_prescription_guard')"));
        $this->assertFalse(Schema::hasTable('jobs'));
        $this->assertFalse(Schema::hasTable('cache'));
        $this->assertFalse(Schema::hasTable('sessions'));
        $this->assertFalse(Schema::hasTable('features'));
    }

    public function test_tenant_migrations_created_every_foundation_table_in_both_schemas_with_their_own_repository(): void
    {
        // Every module's tenant migrations run, not only the foundation's 2026_02_01_* files: count what is on disk.
        $files = count(glob(database_path('migrations/tenant/*.php')) ?: []);
        $this->assertGreaterThanOrEqual(15, $files);

        foreach (['a', 'b'] as $which) {
            $this->asTenant($which);

            foreach (self::TENANT_TABLES as $table) {
                $this->assertTrue(Schema::hasTable($table), "{$table} missing in tenant {$which}");
            }

            $this->assertSame($files, DB::table('migrations')->count());
            $this->assertSame(1, DB::table('branches')->count());
            $this->assertSame(4, DB::table('roles')->count());
        }
    }

    public function test_tenant_tables_live_in_their_schema_and_not_in_public(): void
    {
        $schemas = DB::table('pg_tables')->where('tablename', 'branches')->orderBy('schemaname')->pluck('schemaname')->all();

        $this->assertSame([self::TENANT_A, self::TENANT_B], $schemas);
    }

    public function test_tenants_migrate_pretend_shows_no_drift(): void
    {
        $this->artisan('tenants:migrate', ['--tenant' => ['9001'], '--pretend' => true])
            ->expectsOutputToContain('nothing to migrate')
            ->assertSuccessful();

        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
    }

    public function test_catalog_connection_has_its_own_migrations_repository(): void
    {
        $this->assertTrue(Schema::connection('catalog')->hasTable('migrations'));
        $this->assertSame((string) config('database.connections.catalog.database'), DB::connection('catalog')->getDatabaseName());
    }

    public function test_check_constraints_mirror_the_enums(): void
    {
        $this->asTenant('a');

        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('doctors')->insert(['public_id' => str_repeat('A', 26), 'name' => 'x', 'slug' => 'x-check', 'code' => 'XCHK', 'gender' => 'unknown', 'created_at' => now(), 'updated_at' => now()])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('doctor_leaves')->insert(['doctor_id' => 1, 'starts_on' => '2026-01-02', 'ends_on' => '2026-01-01', 'type' => 'planned', 'created_at' => now(), 'updated_at' => now()])), QueryException::class);
    }
}
