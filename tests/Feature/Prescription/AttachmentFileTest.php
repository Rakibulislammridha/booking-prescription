<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Prescription\Render\PrescriptionRenderer;
use App\Domain\Prescription\Render\RenderOptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Handwriting sheets (§4.12) and the annotation PNG (§4.11) live on the PRIVATE `uploads` disk. This covers the
 * route that serves them to authorised staff — without it the issued view can only list them as chips — plus the
 * fact that they are inlined into the print so a handwritten prescription prints as the prescription it is.
 */
final class AttachmentFileTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        Storage::fake('uploads');
    }

    private function png(int $width = 40, int $height = 24): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 0, 0));
        $path = tempnam(sys_get_temp_dir(), 'hw').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'page.png', 'image/png', null, true);
    }

    public function test_staff_can_view_a_handwriting_sheet_and_every_view_is_audited(): void
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit();
        $draft = $this->draftFor($visit, $doctor);

        $this->post('/panel/prescriptions/'.$draft->public_id.'/handwriting', ['page' => 1, 'png' => $this->png()])->assertCreated();
        $this->post('/panel/prescriptions/'.$draft->public_id.'/handwriting', ['page' => 2, 'png' => $this->png()])->assertCreated();
        $rx = $this->issued($draft->fresh());

        $this->get('/panel/prescriptions/'.$rx->public_id.'/files/handwriting-1.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/panel/prescriptions/'.$rx->public_id.'/files/handwriting-2.png')->assertOk();
        $this->assertAudited(AuditAction::View, $rx, ['event' => 'attachment_viewed', 'file' => 'handwriting-1.png']);
    }

    public function test_the_served_path_is_derived_from_the_row_so_a_crafted_name_reaches_nothing(): void
    {
        [$rx] = $this->issuedWithContent();

        $this->get('/panel/prescriptions/'.$rx->public_id.'/files/handwriting-1.png')->assertNotFound();   // never uploaded
        $this->get('/panel/prescriptions/'.$rx->public_id.'/files/drawing.png')->assertNotFound();
        $this->get('/panel/prescriptions/'.$rx->public_id.'/files/secrets.png')->assertNotFound();
        $this->get('/panel/prescriptions/'.$rx->public_id.'/files/handwriting-0.png')->assertNotFound();
    }

    public function test_an_unauthorised_user_cannot_read_a_prescriptions_attachments(): void
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit();
        $draft = $this->draftFor($visit, $doctor);
        $this->post('/panel/prescriptions/'.$draft->public_id.'/handwriting', ['page' => 1, 'png' => $this->png()])->assertCreated();
        $rx = $this->issued($draft->fresh());

        $this->actingAsStaff(Role::Accountant);
        $this->get('/panel/prescriptions/'.$rx->public_id.'/files/handwriting-1.png')->assertForbidden();
    }

    public function test_handwriting_pages_and_the_drawing_are_inlined_into_the_printed_sheet(): void
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit();
        $draft = $this->draftFor($visit, $doctor);
        $this->post('/panel/prescriptions/'.$draft->public_id.'/handwriting', ['page' => 1, 'png' => $this->png()])->assertCreated();
        $this->post('/panel/prescriptions/'.$draft->public_id.'/drawing', [
            'json' => json_encode(['canvas' => ['w' => 800, 'h' => 480, 'template' => 'abdomen'], 'strokes' => [['tool' => 'pen', 'color' => '#b91c1c', 'width' => 3, 'points' => [[10, 10, 1], [90, 60, 1]]]], 'texts' => [['x' => 100, 'y' => 90, 'text' => 'ব্যথা', 'size' => 16]]]),
            'png' => $this->png(),
        ])->assertOk();
        $this->savedDraft($draft->fresh(), $this->itemsPayload([['slug' => 'paracetamol', 'shorthand' => '1+0+1 5d af', 'label' => '500 mg']]));
        $rx = $this->issued($draft->fresh());

        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();

        // Browsershot renders an HTML string with no session: the sheet must carry the pixels, not a link.
        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringContainsString('class="attachment-page"', $html);
        // The diagram is redrawn as vector from drawing_json, background included.
        $this->assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"', $html);
        $this->assertStringContainsString('Epigastric', $html);
        $this->assertStringContainsString('ব্যথা', $html);
        $this->assertStringContainsString('stroke="#b91c1c"', $html);
        $this->assertStringContainsString('Rx (typed)', $html);        // structured lines print after the sheets
    }

    public function test_a_missing_attachment_file_never_prints_an_empty_page(): void
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit();
        $draft = $this->draftFor($visit, $doctor);
        $this->post('/panel/prescriptions/'.$draft->public_id.'/handwriting', ['page' => 1, 'png' => $this->png()])->assertCreated();
        $rx = $this->issued($draft->fresh());

        Storage::disk('uploads')->delete((string) $rx->handwriting_image_path);
        $snapshot = $rx->snapshot;
        $this->assertNotNull($snapshot);

        $html = app(PrescriptionRenderer::class)->render($snapshot, RenderOptions::fromPad($snapshot->pad(), purpose: 'pdf'));
        $this->assertStringNotContainsString('class="attachment-page"', $html);
    }
}
