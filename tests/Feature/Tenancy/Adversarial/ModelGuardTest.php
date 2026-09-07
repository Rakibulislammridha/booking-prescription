<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial;

use App\Models\Central\Domain;
use App\Models\Central\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Department;
use App\Models\Tenant\Scopes\TenantAssertionScope;
use App\Models\Tenant\User;
use App\Tenancy\Exceptions\TenancyNotInitialized;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Attack surface 2 (model-level guards): every Eloquent path must start in newBaseQueryBuilder(), and tenant_id must
 * never be moved to a foreign tenant.
 */
final class ModelGuardTest extends TestCase
{
    /** Guarantee: the query-time guard cannot be skipped through events, scopes, relations, factories or bulk writes. */
    public function test_the_guard_holds_for_without_events_without_scopes_relations_factories_and_bulk_writes(): void
    {
        $this->assertFalse(Tenancy::check());

        $this->assertThrows(fn () => Branch::withoutEvents(fn () => Branch::query()->count()), TenancyNotInitialized::class);
        $this->assertThrows(fn () => (new Branch)->newQueryWithoutScopes()->count(), TenancyNotInitialized::class);
        $this->assertThrows(fn () => Branch::withoutGlobalScopes()->count(), TenancyNotInitialized::class);
        $this->assertThrows(fn () => User::withoutGlobalScope(TenantAssertionScope::class)->count(), TenancyNotInitialized::class);

        $detached = new Branch;
        $detached->id = 1;
        $this->assertThrows(fn () => $detached->departments()->count(), TenancyNotInitialized::class);
        $this->assertThrows(fn () => $detached->departments, TenancyNotInitialized::class);
        $this->assertThrows(fn () => (new Department)->forceFill(['branch_id' => 1])->branch, TenancyNotInitialized::class);

        $this->assertThrows(fn () => Branch::factory()->create(), TenancyNotInitialized::class);
        $this->assertThrows(fn () => Branch::query()->insert(['name' => 'x', 'code' => 'X', 'slug' => 'x']), TenancyNotInitialized::class);
        $this->assertThrows(fn () => Branch::query()->upsert([['name' => 'x', 'code' => 'X', 'slug' => 'x']], ['slug']), TenancyNotInitialized::class);
        $this->assertThrows(fn () => Branch::query()->where('id', 1)->update(['name' => 'x']), TenancyNotInitialized::class);
        $this->assertThrows(fn () => Branch::query()->where('id', 1)->delete(), TenancyNotInitialized::class);
    }

    /**
     * Documented gap: DB::table() is not guarded. Bare tenant names fail loudly on public (no fallback), but the three
     * names that exist in BOTH schemas resolve silently to the central table when tenancy is not (or no longer) active.
     */
    public function test_documented_gap_raw_builder_is_unguarded_and_dual_named_tables_resolve_silently_to_public(): void
    {
        $this->assertFalse(Tenancy::check());

        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('branches')->count()), QueryException::class);

        $dual = DB::table('pg_tables')->whereIn('schemaname', ['public', self::TENANT_A])
            ->whereIn('tablename', ['personal_access_tokens', 'password_reset_tokens', 'migrations'])
            ->selectRaw('tablename, count(*) as schemas')->groupBy('tablename')->orderBy('tablename')->pluck('schemas', 'tablename')->all();
        $this->assertSame(['migrations' => 2, 'password_reset_tokens' => 2, 'personal_access_tokens' => 2], array_map(intval(...), $dual));

        // These do not throw: a tenant-side DB::table('personal_access_tokens') after Tenancy::end() reads public rows.
        $this->assertGreaterThanOrEqual(0, DB::table('personal_access_tokens')->count());
        $this->assertGreaterThanOrEqual(0, DB::table('password_reset_tokens')->count());
        $this->assertGreaterThan(0, DB::table('migrations')->count());

        // A builder captured while a tenant was active is not re-guarded when it executes later.
        $this->asTenant('a');
        $captured = Branch::query();
        Tenancy::end();
        $this->assertThrows(fn () => DB::transaction(fn () => $captured->count()), QueryException::class);
    }

    /**
     * EXPECTED TO FAIL until fixed: a model instance is not pinned to the tenant it was loaded in. A User loaded in
     * tenant A and saved while tenant B is active issues `UPDATE users ... WHERE id = 1` inside tenant_test_b —
     * overwriting tenant B's hospital admin. AssertsTenantId only checks `creating`; `updating` must compare the
     * row's tenant_id with Tenancy::id() (and TenantModel should remember Tenancy::id() on retrieved/created).
     */
    public function test_a_model_loaded_in_tenant_a_cannot_be_saved_into_tenant_b(): void
    {
        $this->asTenant('a');
        $adminA = User::query()->where('email', 'admin@test-a.test')->firstOrFail();

        $this->asTenant('b');
        $adminB = User::query()->findOrFail($adminA->id);
        $this->assertSame('admin@test-b.test', $adminB->email);
        $this->assertSame(9001, $adminA->tenant_id);

        try {
            $adminA->forceFill(['name' => 'pwned by tenant A'])->save();
        } catch (LogicException|TenantMismatch) {
            // expected once fixed
        }

        $this->assertNotSame('pwned by tenant A', $adminB->fresh()?->name, 'a User loaded in tenant_test_a overwrote tenant_test_b.users row #'.$adminA->id);
    }

    /**
     * EXPECTED TO FAIL until fixed: AssertsTenantId guards `creating` only. On update the row's tenant_id can be moved
     * to another tenant; the UPDATE uses newModelQuery() (no global scope), succeeds, and the row then vanishes from
     * its own tenant's scoped queries. Fix: assert tenant_id on `updating`/`saving` too (and add a CHECK/trigger).
     */
    public function test_tenant_id_cannot_be_moved_to_a_foreign_tenant_by_saving_a_model(): void
    {
        $this->asTenant('a');
        $user = User::factory()->create();

        try {
            $user->forceFill(['tenant_id' => 9002])->save();
        } catch (LogicException) {
            // expected once fixed
        }

        $this->assertSame(9001, (int) DB::table('users')->where('id', $user->id)->value('tenant_id'), 'users.tenant_id was moved to 9002 while tenant 9001 was active');
        $this->assertNotNull(User::query()->find($user->id), 'the row is now invisible to its own tenant');
    }

    /** EXPECTED TO FAIL until fixed: the same through a mass update on the Eloquent builder (no model events). */
    public function test_tenant_id_cannot_be_moved_to_a_foreign_tenant_by_a_mass_update(): void
    {
        $this->asTenant('a');
        $user = User::factory()->create();

        try {
            User::query()->whereKey($user->id)->update(['tenant_id' => 9002]);
        } catch (LogicException) {
            // expected once fixed
        }

        $this->assertSame(9001, (int) DB::table('users')->where('id', $user->id)->value('tenant_id'), 'users.tenant_id was moved to 9002 by a mass update');
    }

    /** EXPECTED TO FAIL until fixed: the same through Eloquent insert()/upsert() (no `creating` event). */
    public function test_tenant_id_cannot_be_set_to_a_foreign_tenant_by_insert_or_upsert(): void
    {
        $this->asTenant('a');
        $row = fn (string $email): array => [
            'public_id' => (string) Str::ulid(), 'tenant_id' => 9002, 'name' => 'x', 'email' => $email, 'password' => Hash::make('x'),
            'created_at' => now(), 'updated_at' => now(),
        ];

        try {
            User::query()->insert($row('adversarial-insert@test.test'));
        } catch (LogicException) {
        }

        try {
            User::query()->upsert([$row('adversarial-upsert@test.test')], ['email'], ['name']);
        } catch (LogicException) {
        }

        $foreign = DB::table('users')->where('tenant_id', 9002)->pluck('email')->all();
        $this->assertSame([], $foreign, 'rows with tenant_id 9002 were written into tenant_test_a.users: '.implode(', ', $foreign));
    }

    /** Guarantee: the assertion scope is attached only to tenant models that declare it; central models are untouched. */
    public function test_the_tenant_assertion_scope_does_not_leak_into_central_or_unscoped_models(): void
    {
        $this->asTenant('a');

        $this->assertStringContainsString('"users"."tenant_id" = ?', User::query()->toSql());
        $this->assertStringNotContainsString('tenant_id', Tenant::query()->toSql());
        $this->assertStringNotContainsString('tenant_id', Domain::query()->toSql());       // has the column, must not be scoped
        $this->assertStringNotContainsString('tenant_id', Branch::query()->toSql());
        $this->assertGreaterThanOrEqual(2, Domain::query()->count());                       // both tenants' primary domains
    }

    /**
     * Guarantee (belt-and-braces, updated with the fix): the per-schema CHECK constraint (`users_tenant_id_check`,
     * `audit_logs_tenant_id_check`) refuses a foreign tenant_id even through the unguarded DB::table() path, and the
     * assertion scope filters on the active tenant, so a stray row could never surface even if it existed.
     */
    public function test_rows_with_a_foreign_tenant_id_are_refused_by_the_schema_and_hidden_from_scoped_queries(): void
    {
        $this->asTenant('a');
        $before = User::query()->count();

        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('users')->insert([
            'public_id' => (string) Str::ulid(), 'tenant_id' => 9002, 'name' => 'stray', 'email' => 'stray@test.test', 'password' => Hash::make('x'),
            'created_at' => now(), 'updated_at' => now(),
        ])), QueryException::class);

        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('users')->where('email', 'admin@test-a.test')->update(['tenant_id' => 9002])), QueryException::class);

        $this->assertSame($before, User::query()->count());
        $this->assertSame($before, User::withoutGlobalScope(TenantAssertionScope::class)->count());
        $this->assertNull(User::query()->where('email', 'stray@test.test')->first());
        $this->assertStringContainsString('"users"."tenant_id" = ?', User::query()->where('email', 'stray@test.test')->toSql());
    }
}
