<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Events\CatalogReconciliationCompleted;
use App\Domain\Catalog\Jobs\ReconcileCatalogReferences;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Models\Catalog\Generic;
use App\Models\Central\CatalogReconciliationReport;
use App\Models\Tenant\CustomBrand;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Two tenant schemas, one orphan generic, one inactive generic, one renamed generic → correct report rows and details;
 * no clinical row modified except the permitted custom-brand deactivation. The inactive generic is a committed catalog
 * change restored in finally (the runtime connection cannot see uncommitted catalog_admin writes).
 */
#[Group('catalog')]
final class ReconcileTest extends TestCase
{
    public function test_reports_orphans_inactive_and_renamed_references_and_deactivates_orphan_custom_brands(): void
    {
        $c = DB::connection('catalog');
        $paracetamol = (int) $c->table('generics')->where('slug', 'paracetamol')->value('id');
        $albendazole = (int) $c->table('generics')->where('slug', 'albendazole')->value('id');
        $cetirizine = (int) $c->table('generics')->where('slug', 'cetirizine')->value('id');

        $this->asTenant('a');
        $ok = CustomBrand::factory()->create(['brand_name' => 'Fine', 'generic_id' => $paracetamol, 'generic_name' => 'Paracetamol']);
        $orphan = CustomBrand::factory()->create(['brand_name' => 'Ghost', 'generic_id' => 999999, 'generic_name' => 'Vanished']);
        $inactive = CustomBrand::factory()->create(['brand_name' => 'Stale', 'generic_id' => $albendazole, 'generic_name' => 'Albendazole']);
        $renamed = CustomBrand::factory()->create(['brand_name' => 'Oldname', 'generic_id' => $cetirizine, 'generic_name' => 'Cetirizine HCl']);
        $checksumA = sha1(DB::table('custom_brands')->orderBy('id')->get()->toJson());

        $this->asTenant('b');
        CustomBrand::factory()->create(['brand_name' => 'Clean', 'generic_id' => $paracetamol, 'generic_name' => 'Paracetamol']);
        $checksumB = sha1(DB::table('custom_brands')->orderBy('id')->get()->toJson());
        Tenancy::end();

        Event::fake([CatalogReconciliationCompleted::class]);
        $context = app(CatalogWriteContext::class);

        try {
            $context->run(fn () => Generic::query()->whereKey($albendazole)->update(['is_active' => false]));   // committed: the scanner reads through `catalog`

            $this->asCentral();
            $this->artisan('catalog:reconcile')->assertSuccessful();
        } finally {
            $context->run(fn () => Generic::query()->whereKey($albendazole)->update(['is_active' => true]));
        }

        Event::assertDispatched(CatalogReconciliationCompleted::class, fn (CatalogReconciliationCompleted $e) => $e->tenants === 2 && $e->orphans === 1 && $e->inactive === 0);

        $allReports = CatalogReconciliationReport::query()->get();
        $this->assertSame(1, $allReports->pluck('run_id')->unique()->count());   // one run covers every registered soft reference

        $reports = CatalogReconciliationReport::query()->where('table_name', 'custom_brands')->orderBy('tenant_id')->get();
        $this->assertCount(2, $reports);                                          // one per tenant for this module's own reference

        $a = $reports->firstWhere('tenant_id', 9001);
        $this->assertSame('custom_brands', $a->table_name);
        $this->assertSame('generic_id', $a->column_name);
        $this->assertSame('orphans_found', $a->status);
        $this->assertSame(4, $a->getAttribute('checked_count'));
        $this->assertSame(1, $a->getAttribute('orphan_count'));
        $this->assertSame([$orphan->id], $a->getAttribute('sample_ids'));
        $details = (array) $a->getAttribute('details');
        $this->assertSame(['999999' => 1], $details['orphan']);
        $this->assertSame([(string) $albendazole => 1], $details['inactive']);
        $this->assertSame([(string) $cetirizine => 1], $details['renamed']);
        $this->assertNotNull($a->getAttribute('catalog_version_id'));

        $b = $reports->firstWhere('tenant_id', 9002);
        $this->assertSame('clean', $b->status);
        $this->assertSame(1, $b->getAttribute('checked_count'));
        $this->assertSame([], (array) $b->getAttribute('details'));

        // Side effects: only the orphan / inactive custom brands were deactivated with the note; everything else untouched.
        Tenancy::run($this->tenant('a'), function () use ($ok, $orphan, $inactive, $renamed): void {
            $this->assertTrue($ok->refresh()->is_active);
            $this->assertTrue($renamed->refresh()->is_active);
            $this->assertFalse($orphan->refresh()->is_active);
            $this->assertMatchesRegularExpression('/^generic missing in catalog dev\./', (string) $orphan->review_note);
            $this->assertFalse($inactive->refresh()->is_active);
            $this->assertSame('Cetirizine HCl', $renamed->generic_name, 'snapshots are never rewritten');
        });
        Tenancy::run($this->tenant('b'), fn () => $this->assertSame($checksumB, sha1(DB::table('custom_brands')->orderBy('id')->get()->toJson())));
        $this->assertNotSame($checksumA, Tenancy::run($this->tenant('a'), fn () => sha1(DB::table('custom_brands')->orderBy('id')->get()->toJson())), 'the permitted deactivation is the only change');
    }

    public function test_command_dispatches_one_tenant_aware_job_per_active_tenant(): void
    {
        Bus::fake([ReconcileCatalogReferences::class]);
        $this->asCentral();
        $this->artisan('catalog:reconcile', ['--tenant' => ['test-a']])->assertSuccessful();

        Bus::assertDispatched(ReconcileCatalogReferences::class, fn (ReconcileCatalogReferences $job) => $job->tenantId === 9001 && $job->queue === 'default');
        Bus::assertDispatchedTimes(ReconcileCatalogReferences::class, 1);
    }
}
