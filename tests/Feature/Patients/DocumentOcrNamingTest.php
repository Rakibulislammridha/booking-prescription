<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Patients\Contracts\DocumentNamer;
use App\Domain\Patients\Contracts\OcrEngine;
use App\Domain\Patients\Enums\DocumentType;
use App\Domain\Patients\Enums\OcrStatus;
use App\Domain\Patients\Exceptions\OcrEngineUnavailable;
use App\Domain\Patients\Jobs\NameUploadedDocument;
use App\Domain\Patients\Services\NullOcrEngine;
use App\Domain\Patients\Services\OcrSettings;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientDocument;
use App\Models\Tenant\Setting;
use App\Support\Clock;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OCR naming of uploaded reports (PRESCRIPTION.md §8), end to end. Two things are proved here above all: a clinic
 * that has configured NOTHING never touches the network and still gets a real title out of a PDF that carries a
 * text layer, and a clinic that has configured Vision never loses a document — or leaks its API key — when the
 * provider misbehaves.
 */
final class DocumentOcrNamingTest extends TestCase
{
    private const TENANT_KEY = 'tenant-vision-key-9001';

    /** @var array<int, string> every line written to the log during the test */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
        $this->asTenant('a');

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    public function test_a_pdf_with_a_text_layer_is_named_from_its_heading_with_no_configuration_at_all(): void
    {
        if (! is_executable((string) config('patients.ocr.pdftotext'))) {
            $this->markTestSkipped('poppler (pdftotext) is not installed on this host.');
        }

        Http::fake();
        Clock::freeze('2026-09-08 10:00');
        $this->actingAsStaff(Role::Receptionist);
        $patient = Patient::factory()->create();

        $response = $this->postJson('/panel/patients/'.$patient->public_id.'/documents', [
            'file' => new UploadedFile($this->fixture(), 'cbc-report.pdf', 'application/pdf', null, true),
            'type' => 'lab_report',
        ])->assertCreated();

        $document = PatientDocument::query()->findOrFail($response->json('data.id'));
        $this->assertSame(OcrStatus::Done, $document->ocr_status);
        $this->assertSame('CBC – 06 Sep 2026', $document->title);
        $this->assertSame('2026-09-06', $document->document_date?->toDateString());
        $this->assertStringContainsString('COMPLETE BLOOD COUNT', (string) $document->ocr_text);

        Http::assertNothingSent();                                     // the text layer is read locally, by poppler
        $response->assertJsonMissingPath('data.ocr_text');
    }

    public function test_an_unconfigured_tenant_keeps_the_null_naming_and_skips(): void
    {
        Http::fake();
        $this->assertInstanceOf(NullOcrEngine::class, app(OcrEngine::class));

        $document = $this->document('image/jpeg', 'a photograph of a report', DocumentType::Imaging, 'jpg');
        $this->runNaming($document);

        $document->refresh();
        $this->assertSame(OcrStatus::Skipped, $document->ocr_status);
        $this->assertStringStartsWith(__('patients.documents.types.imaging').' – ', $document->title);
        $this->assertNull($document->ocr_text);
        Http::assertNothingSent();
    }

    public function test_the_google_driver_reads_a_photo_and_never_writes_the_key_to_the_log(): void
    {
        $this->configureVision();
        Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [['fullTextAnnotation' => ['text' => "Popular Diagnostic\nUltrasonogram of whole abdomen\nDate: 06/09/2026"]]]])]);

        $document = $this->document('image/jpeg', 'JPEG-BYTES', DocumentType::Imaging, 'jpg');
        $this->runNaming($document);

        $document->refresh();
        $this->assertSame(OcrStatus::Done, $document->ocr_status);
        $this->assertSame('Ultrasonogram – 06 Sep 2026', $document->title);
        $this->assertSame('2026-09-06', $document->document_date?->toDateString());
        $this->assertStringContainsString('Ultrasonogram of whole abdomen', (string) $document->ocr_text);

        Http::assertSent(function (Request $request): bool {
            $this->assertStringStartsWith('https://vision.googleapis.com/v1/images:annotate?key=', $request->url());
            $this->assertStringContainsString(self::TENANT_KEY, $request->url(), 'the tenant row must win over the platform key');
            $this->assertSame('DOCUMENT_TEXT_DETECTION', $request['requests'][0]['features'][0]['type']);
            $this->assertSame(['bn', 'en'], $request['requests'][0]['imageContext']['languageHints']);
            $this->assertSame(base64_encode('JPEG-BYTES'), $request['requests'][0]['image']['content']);

            return true;
        });

        // The key is a credential in a query string and the document is clinical data: neither may reach a log line.
        $this->assertKeptOutOfTheLog();
        $this->assertStringNotContainsString(self::TENANT_KEY, (string) json_encode(Setting::query()->where('key', OcrSettings::KEY_API_KEY)->value('value')), 'the settings row must hold the key encrypted');
    }

    public function test_a_pdf_is_never_sent_to_vision_because_images_annotate_cannot_read_one(): void
    {
        $this->configureVision();
        Http::fake();

        $document = $this->document('application/pdf', 'not a pdf poppler can open');
        $this->runNaming($document);

        $document->refresh();
        $this->assertSame(OcrStatus::Skipped, $document->ocr_status);
        Http::assertNothingSent();
    }

    public function test_a_provider_500_is_retried_then_leaves_the_document_failed_rather_than_lost(): void
    {
        $this->configureVision();
        Http::fake(['vision.googleapis.com/*' => Http::response('upstream exploded', 500)]);

        $document = $this->document('image/jpeg', 'JPEG-BYTES', DocumentType::Imaging, 'jpg');
        $job = new NameUploadedDocument($document->id);

        try {
            $job->handle(app(DocumentNamer::class));
            $this->fail('a 5xx must escape the namer so the job retries');
        } catch (OcrEngineUnavailable $e) {
            $this->assertStringNotContainsString(self::TENANT_KEY, $e->getMessage());
            $this->assertSame(OcrStatus::Pending, $document->refresh()->ocr_status, 'a retryable attempt leaves the row alone');

            $job->failed($e);                                          // what the worker does once $tries is spent
        }

        Http::assertSentCount(2);                                      // the client retried inside the attempt
        $document->refresh();
        $this->assertSame(OcrStatus::Failed, $document->ocr_status);
        $this->assertStringStartsWith(__('patients.documents.types.imaging').' – ', $document->title);
        Storage::disk('uploads')->assertExists($document->storage_path);
        $this->assertKeptOutOfTheLog();
    }

    public function test_a_provider_4xx_is_a_permanent_failure_and_still_names_the_document(): void
    {
        $this->configureVision();
        Http::fake(['vision.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid']], 400)]);

        $document = $this->document('image/jpeg', 'JPEG-BYTES', DocumentType::Imaging, 'jpg');
        $this->runNaming($document);

        $document->refresh();
        $this->assertSame(OcrStatus::Failed, $document->ocr_status);
        $this->assertStringStartsWith(__('patients.documents.types.imaging').' – ', $document->title);
        $this->assertNull($document->ocr_text);
        $this->assertKeptOutOfTheLog();
        Http::assertSentCount(1);                                      // a refusal is never worth a second attempt
    }

    public function test_running_the_job_twice_never_overwrites_a_named_document(): void
    {
        $this->configureVision();
        Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [['fullTextAnnotation' => ['text' => 'CBC 06 Sep 2026']]]])]);

        $document = $this->document('image/jpeg', 'JPEG-BYTES', DocumentType::Imaging, 'jpg');
        $this->runNaming($document);
        $this->assertSame('CBC – 06 Sep 2026', $document->refresh()->title);

        Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [['fullTextAnnotation' => ['text' => 'X-ray 01 Jan 2020']]]])]);
        $this->runNaming($document);

        $document->refresh();
        $this->assertSame('CBC – 06 Sep 2026', $document->title);
        $this->assertSame('2026-09-06', $document->document_date?->toDateString());
        $this->assertSame(OcrStatus::Done, $document->ocr_status);
        Http::assertNothingSent();                                     // the second run returned before the engine
    }

    private function configureVision(): void
    {
        config(['patients.ocr.driver' => 'google', 'patients.ocr.key' => 'platform-fallback-key']);
        app(OcrSettings::class)->storeApiKey(self::TENANT_KEY);
    }

    private function runNaming(PatientDocument $document): void
    {
        (new NameUploadedDocument($document->id))->handle(app(DocumentNamer::class));
    }

    private function assertKeptOutOfTheLog(): void
    {
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString(self::TENANT_KEY, $line, 'the API key reached the log');
            $this->assertStringNotContainsString('JPEG-BYTES', $line, 'the document bytes reached the log');
        }
    }

    private function fixture(): string
    {
        return dirname(__DIR__, 2).'/Fixtures/Patients/cbc-report.pdf';
    }

    private function document(string $mime, string $contents, DocumentType $type = DocumentType::LabReport, string $extension = 'pdf'): PatientDocument
    {
        $patient = Patient::factory()->create();
        $path = 'tenants/9001/patients/'.$patient->public_id.'/'.Str::ulid().'.'.$extension;
        Storage::disk('uploads')->put($path, $contents);

        return PatientDocument::factory()->for($patient)->create([
            'type' => $type,
            'title' => __('patients.documents.untitled'),
            'document_date' => null,
            'storage_disk' => 'uploads',
            'storage_path' => $path,
            'mime_type' => $mime,
            'size_bytes' => max(1, strlen($contents)),
            'ocr_status' => OcrStatus::Pending,
            'ocr_text' => null,
        ]);
    }
}
