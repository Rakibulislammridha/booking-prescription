<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Actions\Tenants\ExportTenantData;
use App\Domain\SaaS\Enums\BackupStatus;
use App\Domain\SaaS\Enums\BackupType;
use App\Domain\SaaS\Events\TenantExportCompleted;
use App\Domain\SaaS\Jobs\ExportTenantDataJob;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\TenantBackup;
use App\Models\Tenant\Patient;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * "Full data export on churn" (BRIEF §5.N). The point of the test is the ARCHIVE, not the row: a clinic leaving
 * must be able to open what we hand them without us, so the assertions are on the files inside the zip.
 */
final class TenantExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        Storage::fake('uploads');
    }

    public function test_the_export_archive_contains_a_json_and_a_csv_for_every_tenant_table_plus_a_manifest(): void
    {
        Event::fake([TenantExportCompleted::class]);
        $tenant = $this->tenant('a');

        Tenancy::run($tenant, function (): void {
            Patient::factory()->create(['name' => 'রফিকুল ইসলাম', 'mobile' => '+8801711000222']);
        });

        $backup = app(ExportTenantData::class)->handle($tenant);

        $this->assertSame(BackupType::Export, $backup->type);
        $this->assertSame(BackupStatus::Completed, $backup->status);
        $this->assertNotNull($backup->storage_path);
        $this->assertGreaterThan(0, (int) $backup->getAttribute('size_bytes'));
        $this->assertSame(64, strlen((string) $backup->getAttribute('checksum_sha256')));
        Storage::disk('backups')->assertExists((string) $backup->storage_path);

        $names = $this->entriesOf((string) $backup->storage_path);

        // The spine of a clinic's record, every one of them present in both formats.
        foreach (['patients', 'users', 'branches', 'doctors', 'appointments', 'serials', 'prescriptions', 'visits', 'invoices', 'payments', 'audit_logs'] as $table) {
            $this->assertContains("tables/{$table}.json", $names, "the export must carry {$table}.json");
            $this->assertContains("csv/{$table}.csv", $names, "the export must carry {$table}.csv");
        }

        $this->assertContains('manifest.json', $names);

        // Framework bookkeeping a departing clinic has no use for is deliberately absent.
        $this->assertNotContains('tables/migrations.json', $names);
        $this->assertNotContains('tables/personal_access_tokens.json', $names);

        $manifest = json_decode($this->readEntry((string) $backup->storage_path, 'manifest.json'), true);
        $this->assertSame($tenant->public_id, $manifest['tenant']['public_id']);
        $this->assertSame($tenant->schema_name, $manifest['tenant']['schema']);
        $this->assertArrayHasKey('patients', $manifest['tables']);
        $this->assertGreaterThan(0, $manifest['tables']['patients']);
        $this->assertSame(array_sum($manifest['tables']), $manifest['row_total']);

        // And the data is really in there, readable, in Bangla.
        $patients = json_decode($this->readEntry((string) $backup->storage_path, 'tables/patients.json'), true);
        $this->assertIsArray($patients);
        $this->assertContains('রফিকুল ইসলাম', array_column($patients, 'name'));

        $csv = $this->readEntry((string) $backup->storage_path, 'csv/patients.csv');
        $this->assertStringContainsString('name', explode("\n", $csv)[0]);
        $this->assertStringContainsString('রফিকুল ইসলাম', $csv);

        Event::assertDispatched(TenantExportCompleted::class);
        $this->assertTrue(AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Export->value)->exists());
        $this->assertNotNull($tenant->refresh()->data_export_requested_at);
    }

    public function test_an_export_carries_only_the_requesting_clinics_rows(): void
    {
        $tenantA = $this->tenant('a');
        $tenantB = $this->tenant('b');

        Tenancy::run($tenantA, fn () => Patient::factory()->create(['name' => 'ONLY-IN-A']));
        Tenancy::run($tenantB, fn () => Patient::factory()->create(['name' => 'ONLY-IN-B']));

        $backup = app(ExportTenantData::class)->handle($tenantA);
        $patients = $this->readEntry((string) $backup->storage_path, 'tables/patients.json');

        $this->assertStringContainsString('ONLY-IN-A', $patients);
        $this->assertStringNotContainsString('ONLY-IN-B', $patients, 'an export is scoped by the schema, and the schema is the boundary');
    }

    public function test_the_export_runs_on_the_backups_queue_and_leaves_no_tenancy_behind(): void
    {
        $tenant = $this->tenant('a');
        $job = new ExportTenantDataJob($tenant->id, null);

        $this->assertSame('backups', $job->queue);

        $job->handle(app(ExportTenantData::class));

        $this->assertFalse(Tenancy::check(), 'a central job must not return inside a tenant');
        $this->assertSame(1, TenantBackup::query()->where('tenant_id', $tenant->id)->where('type', BackupType::Export->value)->count());
    }

    public function test_a_super_admin_can_queue_and_download_the_archive(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');

        $this->post(route('super.tenants.exports.store', ['tenant' => $tenant->public_id], false))->assertRedirect();

        $backup = TenantBackup::query()->where('tenant_id', $tenant->id)->where('type', BackupType::Export->value)->firstOrFail();
        $this->assertSame(BackupStatus::Completed, $backup->status);

        $response = $this->get(route('super.tenants.archives.download', ['tenant' => $tenant->public_id, 'backup' => $backup->id], false));
        $response->assertOk();
        $this->assertStringContainsString('.zip', (string) $response->headers->get('content-disposition'));

        // The download itself is audited: it is a complete copy of a clinic's medical records.
        $this->assertSame(2, AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Export->value)->count());
    }

    /** @return array<int, string> */
    private function entriesOf(string $path): array
    {
        $zip = $this->open($path);
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();

        return $names;
    }

    private function readEntry(string $path, string $entry): string
    {
        $zip = $this->open($path);
        $contents = (string) $zip->getFromName($entry);
        $zip->close();

        return $contents;
    }

    private function open(string $path): ZipArchive
    {
        $local = tempnam(sys_get_temp_dir(), 'bp-export-read-').'.zip';
        file_put_contents($local, (string) Storage::disk('backups')->get($path));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($local) === true, 'the export must be a readable zip');

        return $zip;
    }
}
