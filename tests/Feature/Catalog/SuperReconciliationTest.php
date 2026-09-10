<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Central\AuditLogCentral;
use App\Models\Central\CatalogReconciliationReport;
use App\Models\Central\PlatformMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The reconciliation screens (SCHEMA §2.12): filters by clinic / status / run / table, a per-report detail that
 * resolves the flagged catalogue ids and reads the clinic's sample rows, and "notify the clinic" — an owner mail
 * in the platform ledger plus an audit row. Orphans are never deleted; nothing here writes to a clinic.
 */
#[Group('catalog')]
final class SuperReconciliationTest extends TestCase
{
    public function test_the_list_filters_by_clinic_status_run_and_table(): void
    {
        $tenantA = $this->tenant('a');
        $tenantB = $this->tenant('b');
        $paracetamol = (int) DB::connection('catalog')->table('generics')->where('slug', 'paracetamol')->value('id');
        $run = (string) Str::ulid();

        $orphans = CatalogReconciliationReport::factory()->create([
            'run_id' => $run, 'tenant_id' => $tenantA->id, 'table_name' => 'custom_brands', 'column_name' => 'generic_id',
            'checked_count' => 12, 'orphan_count' => 3, 'sample_ids' => [1, 2, 3], 'status' => 'orphans_found',
            'details' => ['orphan' => ['999999' => 3], 'renamed' => [(string) $paracetamol => 2]],
        ]);
        CatalogReconciliationReport::factory()->create(['run_id' => $run, 'tenant_id' => $tenantB->id, 'table_name' => 'prescription_items', 'column_name' => 'brand_id', 'status' => 'clean']);
        CatalogReconciliationReport::factory()->create(['run_id' => (string) Str::ulid(), 'tenant_id' => $tenantB->id, 'table_name' => 'patient_allergies', 'column_name' => 'generic_id', 'status' => 'inactive_found', 'details' => ['inactive' => ['5' => 1]]]);

        $this->actingAsSuper();

        $this->get('/catalog/reconciliation')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Super/Catalog/Reconciliation')
            ->where('filters.unresolved', true)->has('reports', 2)->has('statuses', 4)->has('tables', 3)->has('tenants', 2)->has('runs', 2));

        $this->get('/catalog/reconciliation?unresolved=0')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('reports', 3));
        $this->get('/catalog/reconciliation?unresolved=0&status=clean')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('reports', 1)->where('reports.0.status', 'clean'));
        $this->get('/catalog/reconciliation?tenant='.$tenantA->public_id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('reports', 1)->where('reports.0.id', $orphans->id));
        $this->get('/catalog/reconciliation?unresolved=0&run='.$run)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('reports', 2));
        $this->get('/catalog/reconciliation?unresolved=0&table=patient_allergies')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('reports', 1)->where('reports.0.table_name', 'patient_allergies'));
    }

    public function test_the_detail_resolves_references_and_notifying_the_clinic_is_mailed_and_audited(): void
    {
        $tenant = $this->tenant('a');
        $paracetamol = (int) DB::connection('catalog')->table('generics')->where('slug', 'paracetamol')->value('id');

        $report = CatalogReconciliationReport::factory()->create([
            'tenant_id' => $tenant->id, 'table_name' => 'custom_brands', 'column_name' => 'generic_id',
            'checked_count' => 12, 'orphan_count' => 3, 'sample_ids' => [999999901], 'status' => 'orphans_found',
            'details' => ['orphan' => ['999999' => 3], 'renamed' => [(string) $paracetamol => 2]],
        ]);

        $admin = $this->actingAsSuper();

        $this->get("/catalog/reconciliation/{$report->id}/detail")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Super/Catalog/ReconciliationShow')
            ->where('report.id', $report->id)->where('report.tenant.public_id', $tenant->public_id)->where('report.notified_at', null)
            ->where('references.orphan.0.ref', '999999')->where('references.orphan.0.exists', false)->where('references.orphan.0.rows', 3)
            ->where('references.renamed.0.name', 'Paracetamol')->where('references.renamed.0.exists', true)->where('references.renamed.0.is_active', true)
            ->where('sample', []));

        $this->from("/catalog/reconciliation/{$report->id}/detail")->post("/catalog/reconciliation/{$report->id}/notify", ['note' => 'Please review these three brands.'])
            ->assertRedirect("http://super.bp.test/catalog/reconciliation/{$report->id}/detail")->assertSessionHas('flash.success');

        $mail = PlatformMessage::query()->where('kind', 'reconciliation_tenant')->where('tenant_id', $tenant->id)->latest('id')->first();
        $this->assertInstanceOf(PlatformMessage::class, $mail);
        $this->assertSame('sent', $mail->status);
        $this->assertSame($admin->id, $mail->sent_by_super_admin_id);
        $this->assertSame($tenant->locale->value, $mail->locale);

        $log = AuditLogCentral::query()->where('action', 'update')->where('auditable_type', CatalogReconciliationReport::class)->where('auditable_id', $report->id)->latest('id')->first();
        $this->assertInstanceOf(AuditLogCentral::class, $log);
        $this->assertTrue($log->after['notified']);
        $this->assertSame('Please review these three brands.', $log->after['note']);
        $this->assertSame($tenant->id, $log->tenant_id);

        $this->get("/catalog/reconciliation/{$report->id}/detail")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('report.notified_at', fn ($v) => is_string($v) && $v !== ''));

        // Marking reviewed is still the other action, still audited.
        $this->post("/catalog/reconciliation/{$report->id}/resolve")->assertRedirect();
        $this->assertNotNull($report->refresh()->resolved_at);
        $this->assertSame($admin->id, $report->resolved_by_super_admin_id);
    }

    public function test_the_detail_and_notify_are_closed_to_guests(): void
    {
        $report = CatalogReconciliationReport::factory()->create(['tenant_id' => $this->tenant('a')->id]);
        $this->asCentral();

        $this->get("/catalog/reconciliation/{$report->id}/detail")->assertRedirect('http://super.bp.test/login');
        $this->post("/catalog/reconciliation/{$report->id}/notify")->assertRedirect('http://super.bp.test/login');
        $this->assertSame(0, PlatformMessage::query()->where('kind', 'reconciliation_tenant')->count());
    }
}
