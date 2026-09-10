<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\ToggleCatalogRowActive;
use App\Domain\Catalog\Exceptions\CatalogWriteNotAllowed;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Domain\Clinic\Enums\Role;
use App\Models\Catalog\Brand;
use App\Models\Catalog\DrugInformation;
use App\Models\Catalog\Generic;
use App\Models\Catalog\Icd10Code;
use App\Models\Central\AuditLogCentral;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The console's catalogue browser (BRIEF §3.2): six tabs with server-side search (Postgres fallback here —
 * SCOUT_DRIVER is null in the suite), the read-only drawer, and the three inline edits that are safe centrally —
 * each through CatalogWriteContext, audited to audit_logs_central, refused to anyone who is not a super admin.
 */
#[Group('catalog')]
final class SuperCatalogBrowserTest extends TestCase
{
    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'catalog', 'catalog_admin'];

    public function test_the_browser_lists_every_tab_and_searches_with_the_database_fallback(): void
    {
        $this->actingAsSuper();

        $this->get('/catalog')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Super/Catalog/Browse')
            ->where('tab', 'generics')
            ->where('engine', 'database')
            ->has('tabs', 6)
            ->where('counts.generics', fn ($v) => (int) $v >= 40)
            ->where('counts.brands', fn ($v) => (int) $v >= 120)
            ->has('rows', fn (AssertableInertia $rows) => $rows->has('0', fn (AssertableInertia $row) => $row->has('name')->has('brands_count')->has('is_active')->etc())->etc())
            ->has('meta.total'));

        $this->get('/catalog?tab=brands&q=napa')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tab', 'brands')->where('filters.q', 'napa')->where('rows.0.name', 'Napa')->where('rows.0.generic.name', 'Paracetamol'));

        $this->get('/catalog?tab=icd10&q=sugar')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tab', 'icd10')->where('rows', fn ($rows) => collect(self::rows($rows))->contains(fn ($r) => $r['code'] === 'E11.9')));

        $this->get('/catalog?tab=brands&active=inactive')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.active', 'inactive')
            ->where('rows', fn ($rows) => count(self::rows($rows)) > 0 && collect(self::rows($rows))->every(fn ($r) => $r['is_active'] === false)));

        foreach (['strengths', 'interactions', 'allergy_classes'] as $tab) {
            $this->get('/catalog?tab='.$tab)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('tab', $tab)->where('meta.total', fn ($v) => (int) $v > 0));
        }

        $this->get('/catalog?tab=nope')->assertSessionHasErrors('tab');
    }

    public function test_the_drawer_shows_a_generics_safety_data_and_a_brands_presentations(): void
    {
        $this->actingAsSuper();
        $paracetamol = (int) DB::connection('catalog')->table('generics')->where('slug', 'paracetamol')->value('id');
        $napa = (int) DB::connection('catalog')->table('brands')->where('name', 'Napa')->value('id');

        $generic = $this->getJson("/catalog/generics/{$paracetamol}")->assertOk()
            ->assertJsonPath('kind', 'generics')->assertJsonPath('name', 'Paracetamol')
            ->assertJsonPath('information.public_slug', 'paracetamol')
            ->assertJsonStructure(['brands', 'allergy_classes', 'pregnancy', 'renal', 'hepatic', 'max_doses', 'interactions', 'information', 'version'])
            ->json();

        $this->assertContains('Napa', array_column($generic['brands'], 'name'));
        $this->assertNotEmpty($generic['max_doses']);
        $this->assertNotEmpty($generic['pregnancy']);
        $this->assertNotEmpty($generic['hepatic']);

        $this->getJson("/catalog/brands/{$napa}")->assertOk()->assertJsonPath('kind', 'brands')->assertJsonPath('generic.name', 'Paracetamol')->assertJsonCount(6, 'strengths');

        $e119 = (int) DB::connection('catalog')->table('icd10_codes')->where('code', 'E11.9')->value('id');
        $this->getJson("/catalog/icd10/{$e119}")->assertOk()->assertJsonPath('code', 'E11.9')->assertJsonStructure(['aliases', 'parent', 'children']);

        $this->getJson('/catalog/generics/999999')->assertNotFound();
        $this->getJson("/catalog/drugs/{$paracetamol}")->assertNotFound();      // never a `drugs` kind (C1)
    }

    public function test_inline_edits_go_through_the_write_context_reindex_and_are_audited(): void
    {
        $admin = $this->actingAsSuper();
        $napa = (int) DB::connection('catalog')->table('brands')->where('name', 'Napa')->value('id');
        $paracetamol = (int) DB::connection('catalog')->table('generics')->where('slug', 'paracetamol')->value('id');
        $e119 = (int) DB::connection('catalog')->table('icd10_codes')->where('code', 'E11.9')->value('id');

        // 1. Active switch: deactivated, stamped, audited; never deleted.
        $this->from('/catalog?tab=brands')->put("/catalog/brands/{$napa}/active", ['active' => false])->assertRedirect('http://super.bp.test/catalog?tab=brands')->assertSessionHas('flash.success');
        $row = (array) DB::connection('catalog_admin')->table('brands')->find($napa);
        $this->assertFalse((bool) $row['is_active']);
        $this->assertNotNull($row['discontinued_at']);
        $this->assertFalse(app(CatalogWriteContext::class)->isOpen());

        $log = AuditLogCentral::query()->where('action', 'update')->where('auditable_type', Brand::class)->where('auditable_id', $napa)->latest('id')->first();
        $this->assertInstanceOf(AuditLogCentral::class, $log);
        $this->assertSame($admin->id, $log->super_admin_id);
        $this->assertSame(['is_active' => true], $log->before);
        $this->assertSame(['is_active' => false], $log->after);

        $this->put("/catalog/brands/{$napa}/active", ['active' => true])->assertRedirect();
        $this->assertTrue((bool) DB::connection('catalog_admin')->table('brands')->where('id', $napa)->value('is_active'));
        $this->assertNull(DB::connection('catalog_admin')->table('brands')->where('id', $napa)->value('discontinued_at'));

        // 2. Drug information: text edits are free; the published slug is locked.
        $this->put("/catalog/generics/{$paracetamol}/information", ['indications' => 'Fever and pain (console edit).', 'indications_bn' => 'জ্বর ও ব্যথা।', 'public_slug' => 'paracetamol', 'published' => true])
            ->assertRedirect()->assertSessionHas('flash.success');
        $info = (array) DB::connection('catalog_admin')->table('drug_information')->where('generic_id', $paracetamol)->first();
        $this->assertSame('Fever and pain (console edit).', $info['indications']);
        $this->assertSame('জ্বর ও ব্যথা।', $info['indications_bn']);
        $this->assertNotNull($info['published_at']);
        $this->assertSame(1, AuditLogCentral::query()->where('action', 'update')->where('auditable_type', DrugInformation::class)->count());

        $this->from('/catalog')->put("/catalog/generics/{$paracetamol}/information", ['indications' => 'x', 'public_slug' => 'paracetamol-renamed', 'published' => true])
            ->assertSessionHasErrors('domain');
        $this->assertSame('paracetamol', DB::connection('catalog_admin')->table('drug_information')->where('generic_id', $paracetamol)->value('public_slug'));

        // 3. ICD-10 aliases and the Bangla title.
        $this->put("/catalog/icd10/{$e119}/aliases", ['aliases' => "sugar\ndiabetes\n\nচিনি রোগ", 'title_bn' => 'টাইপ ২ ডায়াবেটিস'])->assertRedirect()->assertSessionHas('flash.success');
        $code = (array) DB::connection('catalog_admin')->table('icd10_codes')->find($e119);
        $this->assertSame(['sugar', 'diabetes', 'চিনি রোগ'], json_decode((string) $code['aliases'], true));
        $this->assertSame('টাইপ ২ ডায়াবেটিস', $code['title_bn']);
        $this->assertSame(1, AuditLogCentral::query()->where('action', 'update')->where('auditable_type', Icd10Code::class)->where('auditable_id', $e119)->count());

        // (The drawer reads through the runtime `catalog` connection, which in the suite runs in its own
        // transaction and cannot see the uncommitted catalog_admin write — so the read-back above is the admin's.)
        $this->assertSame(Generic::class, ToggleCatalogRowActive::MODELS['generics']);
        $this->assertArrayNotHasKey('drugs', ToggleCatalogRowActive::MODELS);
    }

    public function test_the_write_context_refuses_anyone_who_is_not_a_super_admin(): void
    {
        $napa = (int) DB::connection('catalog')->table('brands')->where('name', 'Napa')->value('id');

        // Over HTTP: the console is closed to guests and to tenant staff, so the edit never reaches the action.
        $this->asCentral();
        $this->put("/catalog/brands/{$napa}/active", ['active' => false])->assertRedirect('http://super.bp.test/login');

        $this->asTenant('a');
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->withServerVariables(['HTTP_HOST' => 'super.bp.test']);
        $this->put("/catalog/brands/{$napa}/active", ['active' => false])->assertRedirect('http://super.bp.test/login');
        $this->assertTrue((bool) DB::connection('catalog_admin')->table('brands')->where('id', $napa)->value('is_active'));

        // In code: PHPUnit runs "in console", which the context trusts — so hand it an application that says it
        // is not, and prove the guard on its own.
        $app = $this->createMock(Application::class);
        $app->method('runningInConsole')->willReturn(false);
        $this->app->instance(CatalogWriteContext::class, new CatalogWriteContext($app, $this->app->make('db')));
        $this->assertFalse(auth('super')->check(), 'only tenant staff is signed in — no super admin');

        $this->expectException(CatalogWriteNotAllowed::class);
        app(ToggleCatalogRowActive::class)->handle('brands', $napa, false);
    }

    /**
     * A page prop as AssertableInertia hands it to a `where` closure: a Collection for lists, an array otherwise.
     *
     * @return array<int|string, mixed>
     */
    private static function rows(mixed $value): array
    {
        return $value instanceof Collection ? $value->all() : (array) $value;
    }
}
