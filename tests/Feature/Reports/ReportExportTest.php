<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Data\ReportTable;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\Enums\ReportKind;
use App\Domain\Reports\Export\PdfWriter;
use App\Domain\Reports\Export\ReportExporter;
use App\Domain\Reports\Export\XlsxWriter;
use App\Domain\Reports\Jobs\GenerateReportExport;
use App\Domain\Reports\Services\ReportData;
use App\Domain\Reports\Services\ReportScopeResolver;
use App\Models\Tenant\ReportExport;
use App\Support\Clock;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Reports\Concerns\ReportFixture;
use Tests\TestCase;
use ZipArchive;

/**
 * The exported file must say exactly what the screen says (BRIEF §5.L). Both come from `ReportData`, so these
 * tests prove the numbers survive each writer — and that Bangla survives the PDF, which is the one format
 * where a wrong font stack turns `ডা. রহমান` into boxes.
 */
final class ReportExportTest extends TestCase
{
    use ReportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::HospitalAdmin);
        // A panel request runs in the language SetTenantLocale picks (the session's, else the tenant's, which
        // defaults to Bangla). Pin it here so the assertions below are about the FILE, not about which language
        // the tester happened to be in; `test_the_export_follows_the_staff_members_own_language` covers the
        // Bangla side deliberately.
        $this->withSession(['locale' => 'en']);
        $this->seedReportFixture();
        App::setLocale('en');
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function march(): array
    {
        return ['from' => '2026-03-01', 'to' => '2026-03-10'];
    }

    public function test_csv_carries_the_same_doctor_rows_the_page_shows(): void
    {
        $response = $this->get(route('panel.reports.export', ['report' => 'appointments', 'format' => 'csv'] + $this->march(), false));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $csv = $this->streamed($response);

        // Excel on a Bangladeshi desktop needs the BOM or Bangla names arrive as mojibake.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rahman = null;

        foreach (array_filter(explode("\n", trim($csv))) as $line) {
            $cells = str_getcsv($line, ',', '"', '\\');

            if (($cells[0] ?? null) === 'Dr. Rahman') {
                $rahman = $cells;
            }
        }

        $this->assertNotNull($rahman, 'the doctor table must be in the file');
        // Exactly the on-screen row: 8 booked, 4 completed, 1 no-show, 3 cancelled, 5 attendance opportunities.
        $this->assertSame(['Dr. Rahman', '8', '4', '1', '3', '5'], array_slice($rahman, 0, 6));

        // And the definitions travel with the file, so a spreadsheet on someone's laptop still says what
        // "no-show" means.
        $this->assertStringContainsString('A no-show is a serial whose final status is no-show', $csv);
    }

    public function test_every_format_reports_the_same_numbers(): void
    {
        $page = app(ReportData::class)->for(
            ReportKind::Appointments,
            ReportFilters::forDays('2026-03-01', '2026-03-10'),
            app(ReportScopeResolver::class)->for($this->app['auth']->guard('web')->user()),
        );
        $onScreen = $this->rows($page['data']['by_doctor'])->firstWhere('doctor_name', 'Dr. Rahman');
        $this->assertSame(8, $onScreen['booked'] ?? null);

        $csv = $this->streamed($this->get(route('panel.reports.export', ['report' => 'appointments', 'format' => 'csv'] + $this->march(), false)));
        $this->assertStringContainsString('"Dr. Rahman",8,4,1,3,5', $csv);

        $sheet = $this->workbookSheet('appointments');
        $this->assertStringContainsString('<is><t xml:space="preserve">Dr. Rahman</t></is>', $sheet);
        $this->assertStringContainsString('<v>8</v>', $sheet);

        $html = app(PdfWriter::class)->html(
            app(ReportExporter::class)->table(
                ReportKind::Appointments,
                ReportFilters::forDays('2026-03-01', '2026-03-10'),
                app(ReportScopeResolver::class)->for($this->app['auth']->guard('web')->user()),
            ),
        );
        $this->assertStringContainsString('Dr. Rahman', $html);
        $this->assertStringContainsString('>8<', $html);
    }

    public function test_the_excel_file_is_a_readable_workbook(): void
    {
        $response = $this->get(route('panel.reports.export', ['report' => 'appointments', 'format' => 'xlsx'] + $this->march(), false));
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));

        $path = tempnam(sys_get_temp_dir(), 'bp-test-xlsx');
        file_put_contents($path, $this->streamed($response));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'the download must be a valid zip container');
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/styles.xml'] as $part) {
            $this->assertNotFalse($zip->locateName($part), "missing workbook part {$part}");
        }
        $zip->close();
        @unlink($path);

        $this->assertSame('A', XlsxWriter::columnName(1));
        $this->assertSame('Z', XlsxWriter::columnName(26));
        $this->assertSame('AA', XlsxWriter::columnName(27));
    }

    public function test_bangla_survives_the_csv_and_the_excel_file(): void
    {
        $bangla = 'ডা. রহমান';
        $this->rahman->forceFill(['name' => $bangla])->save();

        $csv = $this->streamed($this->get(route('panel.reports.export', ['report' => 'appointments', 'format' => 'csv'] + $this->march(), false)));
        $this->assertStringContainsString($bangla, $csv);

        $this->assertStringContainsString($bangla, $this->workbookSheet('appointments'));
    }

    /**
     * The PDF path itself: a real Chrome render, so the assertion is about the produced document rather than
     * about our HTML. Skipped where Chrome is not installed (the `browsershot` group convention).
     */
    #[Group('browsershot')]
    public function test_bangla_renders_in_the_pdf_export(): void
    {
        $writer = app(PdfWriter::class);

        if (! $writer->available()) {
            $this->markTestSkipped('Chrome is not installed on this host.');
        }

        $this->rahman->forceFill(['name' => 'ডা. রহমান'])->save();

        $table = app(ReportExporter::class)->table(
            ReportKind::Appointments,
            ReportFilters::forDays('2026-03-01', '2026-03-10'),
            app(ReportScopeResolver::class)->for($this->app['auth']->guard('web')->user()),
        );

        // The HTML Chrome is given must inline the Bangla face — a linked /fonts/*.woff2 resolves to nothing
        // when Browsershot renders a string with no base URL, and the conjuncts come out as boxes.
        $html = $writer->html($table);
        $this->assertStringContainsString("font-family:'Noto Sans Bengali'", $html);
        $this->assertStringContainsString('data:font/woff2;base64,', $html);
        $this->assertStringContainsString('ডা. রহমান', $html);

        // The same table renders again: rows are a factory, not a one-shot generator.
        $this->assertSame($html, $writer->html($table));

        $pdf = $writer->bytes($table);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf), 'a real page, not an empty document');
    }

    public function test_without_chrome_the_pdf_route_still_answers_with_the_printable_document(): void
    {
        $response = $this->get(route('panel.reports.export', ['report' => 'appointments', 'format' => 'pdf'] + $this->march(), false));
        $response->assertOk();

        $type = (string) $response->headers->get('Content-Type');
        $this->assertTrue(str_contains($type, 'application/pdf') || str_contains($type, 'text/html'));
    }

    public function test_the_export_follows_the_staff_members_own_language(): void
    {
        $this->withSession(['locale' => 'bn']);
        $csv = $this->streamed($this->get(route('panel.reports.export', ['report' => 'appointments', 'format' => 'csv'] + $this->march(), false)));

        // Title, header and the metric definitions all arrive in Bangla — the footnotes travel with the file.
        $this->assertStringContainsString('অ্যাপয়েন্টমেন্ট', $csv);
        $this->assertStringContainsString('ডাক্তার', $csv);
        $this->assertStringContainsString('2026-03-01 থেকে 2026-03-10', $csv);
        $this->assertStringContainsString('অনুপস্থিত মানে যে সিরিয়ালের শেষ অবস্থা অনুপস্থিত', $csv);
        // The numbers are the same numbers whatever the language.
        $this->assertStringContainsString('"Dr. Rahman",8,4,1,3,5', $csv);
    }

    public function test_every_export_writes_an_audit_entry_naming_who_took_what(): void
    {
        $user = $this->app['auth']->guard('web')->user();
        $this->get(route('panel.reports.export', ['report' => 'clinical', 'format' => 'csv'] + $this->march(), false))->assertOk();

        $this->assertAudited(AuditAction::Export, $user, [
            'report' => 'clinical',
            'format' => 'csv',
            'from' => '2026-03-01',
            'to' => '2026-03-10',
        ]);
    }

    public function test_a_large_export_goes_to_the_reports_queue_instead_of_holding_the_request(): void
    {
        Bus::fake();

        $exporter = app(ReportExporter::class);
        $scope = app(ReportScopeResolver::class)->for($this->app['auth']->guard('web')->user());
        $filters = ReportFilters::forDays('2026-03-01', '2026-03-10');
        $table = $exporter->table(ReportKind::Appointments, $filters, $scope);

        $this->assertFalse($exporter->isLarge($table), 'a two-doctor table is not large');

        $huge = new ReportTable('t', 's', ['a'], fn (): array => [], knownRowCount: ReportExporter::SYNC_ROW_LIMIT + 1);
        $this->assertTrue($exporter->isLarge($huge));

        $export = $exporter->queue(ReportKind::Appointments, ExportFormat::Csv, $filters, $scope, $this->app['auth']->guard('web')->user(), $huge);

        $this->assertSame(ExportStatus::Pending, $export->status);
        Bus::assertDispatched(GenerateReportExport::class, fn (GenerateReportExport $job): bool => $job->exportId === $export->id && $job->queue === 'reports');
    }

    public function test_the_queued_job_writes_the_file_and_marks_the_row_ready(): void
    {
        Storage::fake(ReportExporter::DISK);

        $scope = app(ReportScopeResolver::class)->for($this->app['auth']->guard('web')->user());
        $export = app(ReportExporter::class)->queue(
            ReportKind::Appointments,
            ExportFormat::Csv,
            ReportFilters::forDays('2026-03-01', '2026-03-10'),
            $scope,
            $this->app['auth']->guard('web')->user(),
            app(ReportExporter::class)->table(ReportKind::Appointments, ReportFilters::forDays('2026-03-01', '2026-03-10'), $scope),
        );

        (new GenerateReportExport($export->id, $scope->toArray()))->handle(app(ReportExporter::class));

        $export->refresh();
        $this->assertSame(ExportStatus::Ready, $export->status);
        $this->assertNotNull($export->file_path);
        $this->assertStringStartsWith('tenants/9001/reports/appointments/', (string) $export->file_path);
        Storage::disk(ReportExporter::DISK)->assertExists((string) $export->file_path);
        $this->assertStringContainsString('"Dr. Rahman",8,4,1,3,5', Storage::disk(ReportExporter::DISK)->get((string) $export->file_path));

        // And the archive page lists it as downloadable.
        $this->get(route('panel.reports.exports.index', [], false))->assertInertia(
            fn (AssertableInertia $page) => $page->component('Reports/Exports')->where('exports.0.downloadable', true),
        );
    }

    public function test_a_users_export_is_not_downloadable_by_someone_else(): void
    {
        Storage::fake(ReportExporter::DISK);
        $mine = ReportExport::factory()->ready()->create(['user_id' => $this->app['auth']->guard('web')->id()]);

        $this->actingAsStaff(Role::Accountant);
        $this->get(route('panel.reports.exports.show', ['export' => $mine->public_id], false))->assertForbidden();
    }

    /** @param  TestResponse<Response>  $response */
    private function streamed(TestResponse $response): string
    {
        ob_start();
        $response->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    private function workbookSheet(string $report): string
    {
        $response = $this->get(route('panel.reports.export', ['report' => $report, 'format' => 'xlsx'] + $this->march(), false));
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'bp-test-xlsx');
        file_put_contents($path, $this->streamed($response));

        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        return $sheet;
    }
}
