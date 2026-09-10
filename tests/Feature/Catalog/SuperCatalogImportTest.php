<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Enums\CatalogJobKind;
use App\Domain\Catalog\Enums\CatalogJobStatus;
use App\Domain\Catalog\Import\CatalogImporter;
use App\Domain\Catalog\Jobs\RebuildCatalogSearchIndex;
use App\Domain\Catalog\Jobs\ReconcileCatalogReferences;
use App\Domain\Catalog\Jobs\RunCatalogImport;
use App\Domain\Catalog\Jobs\RunCatalogReconciliation;
use App\Domain\Catalog\Queries\CatalogImportsScreen;
use App\Domain\Catalog\Search\CatalogSearchIndexer;
use App\Domain\Catalog\Services\CatalogJobs;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Domain\Catalog\Services\ImportBundleStore;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\CatalogJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Catalogue imports from the console (CATALOG.md §5): a bundle is validated on upload, a dry run produces a report
 * without committing, an apply creates the catalog_versions row and its issues — both as queued jobs, never inside
 * the request — and the two maintenance buttons dispatch jobs too. Every step is an audit_logs_central row.
 */
#[Group('catalog')]
final class SuperCatalogImportTest extends TestCase
{
    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'catalog', 'catalog_admin'];

    /** @var list<string> */
    private array $bundles = [];

    protected function tearDown(): void
    {
        $store = app(ImportBundleStore::class);

        foreach ($this->bundles as $dir) {
            $store->delete($dir);
        }

        parent::tearDown();
    }

    public function test_an_upload_is_validated_before_anything_is_queued(): void
    {
        $this->actingAsSuper();
        $directoriesBefore = count(glob(app(ImportBundleStore::class)->root().'/*', GLOB_ONLYDIR) ?: []);

        // Not a CSV.
        $this->from('/catalog/imports')->post('/catalog/imports', ['files' => [UploadedFile::fake()->create('list.xlsx', 10)], 'source' => 'dgda'])
            ->assertSessionHasErrors('files.0');

        // A table-named file missing the columns the importer reads unconditionally.
        $this->from('/catalog/imports')->post('/catalog/imports', ['files' => [UploadedFile::fake()->createWithContent('generics.csv', "slug,foo\nx,y\n")], 'source' => 'seed'])
            ->assertSessionHasErrors('domain');

        // A DGDA sheet without a brand/generic column.
        $this->from('/catalog/imports')->post('/catalog/imports', ['files' => [UploadedFile::fake()->createWithContent('dgda-2026.csv', "a,b\n1,2\n")], 'source' => 'dgda'])
            ->assertSessionHasErrors('domain');

        // A bad release label.
        $this->from('/catalog/imports')->post('/catalog/imports', ['files' => [UploadedFile::fake()->createWithContent('routes.csv', "code,name,abbreviation\npo,Oral,PO\n")], 'source' => 'seed', 'version' => 'bad label!'])
            ->assertSessionHasErrors('version');

        $this->assertSame(0, CatalogJob::query()->count());
        $this->assertSame($directoriesBefore, count(glob(app(ImportBundleStore::class)->root().'/*', GLOB_ONLYDIR) ?: []), 'a refused bundle leaves no files behind');
    }

    public function test_dry_run_then_apply_run_as_queued_jobs_and_produce_reports_versions_and_issues(): void
    {
        Queue::fake();
        $admin = $this->actingAsSuper();
        $version = 'console.'.Str::lower(substr((string) Str::ulid(), -10));    // ≤ 32 chars, like `2026.09.1`

        $response = $this->post('/catalog/imports', ['files' => $this->bundle(), 'source' => 'seed', 'version' => $version, 'release_ref' => 'DGDA bulletin 42'])->assertRedirect();
        $job = CatalogJob::query()->latest('id')->firstOrFail();
        $this->bundles[] = (string) $job->bundle_path;
        $response->assertRedirect('http://super.bp.test/catalog/imports/'.$job->public_id);

        $this->assertSame(CatalogJobStatus::Uploaded, $job->status);
        $this->assertSame(['brands.csv', 'forms.csv', 'generics.csv', 'icd10.csv', 'routes.csv'], $job->bundle_files);
        $this->assertSame($version, $job->version);
        $this->assertSame(64, strlen((string) $job->checksum));
        $this->assertSame(2, $job->progress['rows']['generics.csv']);
        $this->assertSame(1, AuditLogCentral::query()->where('action', 'create')->where('auditable_type', CatalogJob::class)->where('auditable_id', $job->id)->count());
        Queue::assertNothingPushed();

        // 1. Dry run: queued, then run by a worker; the report says what WOULD change and nothing is written.
        $this->post("/catalog/imports/{$job->public_id}/dry-run")->assertRedirect()->assertSessionHas('flash.success');
        Queue::assertPushed(RunCatalogImport::class, fn (RunCatalogImport $j) => $j->jobId === $job->id);
        $this->assertSame(CatalogJobStatus::Queued, $job->refresh()->status);
        $this->assertSame('dry_run', $job->mode);

        $this->runImport($job);

        $job->refresh();
        $this->assertSame(CatalogJobStatus::Succeeded, $job->status, (string) $job->error);
        $this->assertSame('dry_run', $job->report['status'] ?? null);
        $this->assertSame(2, $job->report['row_counts']['generics']['inserted']);
        $this->assertSame(1, $job->report['row_counts']['brands']['inserted']);
        $this->assertSame(1, $job->report['row_counts']['icd10_codes']['inserted']);
        $this->assertSame(['unknown_form' => 1], $job->report['issues']);
        $this->assertSame('unknown_form', $job->report['issue_samples'][0]['kind']);
        $this->assertSame('Testo', $job->report['issue_samples'][0]['payload']['brand']);
        $this->assertSame(100, $job->progress['percent']);
        $this->assertNull($job->catalog_version_id);
        $this->assertSame(0, DB::connection('catalog_admin')->table('catalog_versions')->where('version', $version)->count(), 'a dry run commits nothing');
        $this->assertSame(0, DB::connection('catalog_admin')->table('generics')->where('slug', 'testomycin')->count());

        $this->get("/catalog/imports/{$job->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Super/Catalog/Imports/Show')
            ->where('job.status', 'succeeded')->where('job.mode', 'dry_run')->where('job.report.status', 'dry_run')
            ->where('job.has_bundle', true)->where('job.requested_by', $admin->name)
            ->where('issues', [])->where('version', null));

        // 2. Apply: the same bundle, for real.
        $this->post("/catalog/imports/{$job->public_id}/apply")->assertRedirect()->assertSessionHas('flash.success');
        Queue::assertPushed(RunCatalogImport::class, 2);
        $this->assertSame('apply', $job->refresh()->mode);

        $this->runImport($job);

        $job->refresh();
        $this->assertSame(CatalogJobStatus::Succeeded, $job->status, (string) $job->error);
        $this->assertSame('applied', $job->report['status']);
        $this->assertNotNull($job->catalog_version_id);
        $this->assertFalse($job->report['indexed'], 'scout is not meilisearch in the suite');

        $row = (array) DB::connection('catalog_admin')->table('catalog_versions')->where('version', $version)->first();
        $this->assertSame('applied', $row['status']);
        $this->assertSame($admin->email, $row['applied_by']);
        $this->assertSame('DGDA bulletin 42', $row['dgda_release_ref']);
        $this->assertSame(1, DB::connection('catalog_admin')->table('generics')->where('slug', 'testomycin')->where('is_active', true)->count());
        $this->assertSame(1, DB::connection('catalog_admin')->table('brands')->where('name', 'Testo')->count());
        $this->assertSame(1, DB::connection('catalog_admin')->table('catalog_import_issues')->where('catalog_version_id', $job->catalog_version_id)->where('kind', 'unknown_form')->count());

        $applied = AuditLogCentral::query()->where('action', 'create')->where('auditable_type', CatalogJob::class)->where('auditable_id', $job->id)->latest('id')->first();
        $this->assertInstanceOf(AuditLogCentral::class, $applied);
        $this->assertTrue($applied->after['applied'] ?? false);
        $this->assertSame($version, $applied->after['version']);
        $this->assertSame($admin->id, $applied->super_admin_id);

        $this->get("/catalog/imports/{$job->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('job.report.status', 'applied')->where('job.catalog_version_id', $job->catalog_version_id)->has('issues')->has('version'));

        // The result page's issue list and the versions list, read the way the page reads them. (The page itself
        // reads through the runtime `catalog` connection, which in the suite runs in its own transaction and
        // cannot see the uncommitted catalog_admin rows — so the queries are exercised inside the write context.)
        $screen = app(CatalogImportsScreen::class);
        $issues = app(CatalogWriteContext::class)->run(fn () => $screen->issues((int) $job->catalog_version_id));
        $this->assertCount(1, $issues);
        $this->assertSame('unknown_form', $issues[0]['kind']);
        $this->assertSame('Testo', $issues[0]['payload']['brand']);
        $this->assertNull($issues[0]['resolved_at']);

        $versions = app(CatalogWriteContext::class)->run(fn () => $screen->versions());
        $this->assertSame($version, $versions[0]['version']);
        $this->assertSame('applied', $versions[0]['status']);
        $this->assertSame($admin->email, $versions[0]['applied_by']);
        $this->assertSame('DGDA bulletin 42', $versions[0]['dgda_release_ref']);
        // (`issues_open` is counted on the runtime connection and cannot see the uncommitted rows here; the issue
        // list above is the same data through the model.)
        $this->assertSame(2, $versions[0]['row_counts']['generics']['inserted']);
        $this->assertSame('superseded', $versions[1]['status'], 'the previously applied release is superseded');

        // The Imports page lists the job; the bundle can now be re-run only with force.
        $this->get('/catalog/imports')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Super/Catalog/Imports/Index')
            ->where('jobs.0.public_id', $job->public_id)->where('jobs.0.status', 'succeeded')->where('jobs.0.mode', 'apply')->where('busy', false)
            ->has('versions')->has('search.driver')->has('open_issues.open'));

        // A job of the same kind still running refuses a second one.
        $job->forceFill(['status' => CatalogJobStatus::Running])->save();
        $this->post('/catalog/imports', ['files' => $this->bundle(), 'source' => 'seed'])->assertRedirect();
        $other = CatalogJob::query()->latest('id')->firstOrFail();
        $this->bundles[] = (string) $other->bundle_path;
        $this->from('/catalog/imports')->post("/catalog/imports/{$other->public_id}/dry-run")->assertSessionHasErrors('domain');
        $this->assertSame(CatalogJobStatus::Uploaded, $other->refresh()->status);
    }

    public function test_index_rebuild_and_reconciliation_are_dispatched_as_jobs_and_audited(): void
    {
        Queue::fake();
        $admin = $this->actingAsSuper();

        $this->post('/catalog/index/rebuild')->assertRedirect('http://super.bp.test/catalog/imports')->assertSessionHas('flash.success');
        Queue::assertPushed(RebuildCatalogSearchIndex::class, 1);
        $reindex = CatalogJob::query()->ofKind(CatalogJobKind::Reindex)->latest('id')->firstOrFail();
        $this->assertSame(CatalogJobStatus::Queued, $reindex->status);
        $this->assertSame($admin->id, $reindex->requested_by_super_admin_id);
        $this->assertSame(1, AuditLogCentral::query()->where('action', 'create')->where('auditable_type', CatalogJob::class)->where('auditable_id', $reindex->id)->count());

        // A second rebuild while one is queued is refused.
        $this->from('/catalog/imports')->post('/catalog/index/rebuild')->assertSessionHasErrors('domain');
        Queue::assertPushed(RebuildCatalogSearchIndex::class, 1);

        // Without Meilisearch the worker records a failure instead of pretending.
        (new RebuildCatalogSearchIndex($reindex->id))->handle(app(CatalogSearchIndexer::class), app(CatalogJobs::class));
        $this->assertSame(CatalogJobStatus::Failed, $reindex->refresh()->status);
        $this->assertSame('catalog.search_unavailable', $reindex->progress['code']);

        // Reconciliation now: one job that fans out the per-tenant sweeps under a fresh run id.
        $this->post('/catalog/reconcile/run')->assertRedirect()->assertSessionHas('flash.success');
        Queue::assertPushed(RunCatalogReconciliation::class, 1);
        $reconcile = CatalogJob::query()->ofKind(CatalogJobKind::Reconcile)->latest('id')->firstOrFail();

        (new RunCatalogReconciliation($reconcile->id))->handle(app(CatalogJobs::class));
        $this->assertSame(CatalogJobStatus::Succeeded, $reconcile->refresh()->status);
        $this->assertSame(26, strlen((string) $reconcile->report['run_id']));
        $this->assertGreaterThanOrEqual(2, $reconcile->report['tenants']);
        Queue::assertPushed(ReconcileCatalogReferences::class, fn (ReconcileCatalogReferences $j) => $j->runId === $reconcile->report['run_id']);

        $this->get('/catalog/imports')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reindex.status', 'failed')->where('reconcile.status', 'succeeded')->where('reconcile.report.run_id', $reconcile->report['run_id']));
    }

    public function test_the_import_screens_are_closed_to_guests(): void
    {
        $this->asCentral();
        $this->get('/catalog/imports')->assertRedirect('http://super.bp.test/login');
        $this->post('/catalog/imports', [])->assertRedirect('http://super.bp.test/login');
        $this->post('/catalog/index/rebuild')->assertRedirect('http://super.bp.test/login');
        $this->post('/catalog/reconcile/run')->assertRedirect('http://super.bp.test/login');
    }

    private function runImport(CatalogJob $job): void
    {
        (new RunCatalogImport($job->id))->handle(app(CatalogImporter::class), app(CatalogJobs::class), app(CentralAudit::class));
    }

    /**
     * A tiny seed-format bundle: two new molecules, one brand with a presentation whose form the catalogue does not
     * know (→ one `unknown_form` issue), one new ICD-10 code. Routes/forms are the seed's own so nothing else moves.
     *
     * @return list<UploadedFile>
     */
    private function bundle(): array
    {
        return [
            UploadedFile::fake()->createWithContent('routes.csv', "code,name,name_bn,abbreviation,is_systemic\npo,Oral,মুখে,PO,1\n"),
            UploadedFile::fake()->createWithContent('forms.csv', "code,name,name_bn,abbreviation,default_unit,default_route_code,is_liquid,pack_unit\ntab,Tablet,ট্যাবলেট,Tab,tab,po,0,pack\n"),
            UploadedFile::fake()->createWithContent('generics.csv', "slug,name,name_bn,atc_code,therapeutic_class,aliases,is_controlled,is_pediatric_weight_based,components,default_presentations,status\ntestomycin,Testomycin,টেস্টোমাইসিন,J01XX99,Test antibiotic,testo|টেস্টো,0,0,,tab:100 mg:10x10,active\ntestoprazole,Testoprazole,,A02BC99,Test PPI,,0,0,,tab:20 mg:10x10,active\n"),
            UploadedFile::fake()->createWithContent('brands.csv', "generic_slug,name,manufacturer,dar_number,popularity,aliases,presentations,status\ntestomycin,Testo,Test Pharma,DAR-TEST-1,5,,tab:100 mg:10x10; lozenge:50 mg:10,active\n"),
            UploadedFile::fake()->createWithContent('icd10.csv', "code,title,title_bn,chapter,block,parent_code,aliases,is_billable\nZ99.9,Test dependence,টেস্ট,XXI,Z99-Z99,,test dependence,1\n"),
        ];
    }
}
