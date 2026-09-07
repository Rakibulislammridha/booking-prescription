<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Prescription\Actions\AmendPrescription;
use App\Domain\Prescription\Actions\VoidPrescription;
use App\Domain\Prescription\Services\PdfStorage;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Prescription;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PRESCRIPTION.md §7.4 — the public /rx/{code} page. No login, unguessable code, rendered from the frozen
 * snapshot, with the banner a pharmacist needs: valid, superseded by version N, or voided.
 */
final class VerificationPageTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_anyone_with_the_link_sees_the_verified_copy_and_the_hit_is_audited(): void
    {
        [$rx] = $this->issuedWithContent();
        $code = (string) $rx->verification_code;

        // No session, no staff user: the link IS the credential.
        $this->app['auth']->forgetGuards();
        $this->post('/panel/logout');

        $response = $this->get('/rx/'.$code)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $html = $response->getContent();

        $this->assertStringContainsString('Valid prescription', $html);
        $this->assertStringContainsString('Napa', $html);
        $this->assertStringContainsString('প্রচুর পানি পান করুন', $html);
        $this->assertStringContainsString('Rehana Begum', $html);
        $this->assertStringContainsString('>COPY', $html);                    // a screen copy is never the paper
        $this->assertStringContainsString($code, $html);
        // Only the identifiers the prescription itself carries — the mobile was masked into the snapshot at issue.
        $this->assertStringNotContainsString((string) $rx->patient->mobile, $html);

        $this->assertAudited(AuditAction::View, $rx, ['event' => 'verified_view']);
    }

    public function test_a_malformed_or_unknown_code_is_a_404_and_leaks_nothing(): void
    {
        $this->get('/rx/short')->assertNotFound();
        $this->get('/rx/../../etc/passwd')->assertNotFound();
        $this->get('/rx/ZZZZZZZZZZZZ')->assertNotFound();          // valid shape, no such row
    }

    public function test_an_amended_version_shows_the_superseded_banner_and_links_the_chain(): void
    {
        [$rx] = $this->issuedWithContent();
        $draft = app(AmendPrescription::class)->handle($rx, 'Wrong strength', Actor::user((int) auth('web')->id()));
        $v2 = $this->issued($draft->fresh());

        $html = $this->get('/rx/'.$rx->verification_code)->assertOk()->getContent();
        $this->assertStringContainsString('Superseded', $html);
        $this->assertStringContainsString('Replaced by version 2', $html);
        $this->assertStringContainsString((string) $v2->verification_code, $html);   // the reader is led to the current one

        $latest = $this->get('/rx/'.$v2->verification_code)->assertOk()->getContent();
        $this->assertStringContainsString('Valid prescription', $latest);
    }

    public function test_a_voided_prescription_says_so_loudly_and_still_renders(): void
    {
        [$rx] = $this->issuedWithContent();
        app(VoidPrescription::class)->handle($rx, 'Issued to the wrong patient', Actor::user((int) auth('web')->id()));

        $html = $this->get('/rx/'.$rx->verification_code)->assertOk()->getContent();
        $this->assertStringContainsString('Voided prescription', $html);
        $this->assertStringContainsString('must not be dispensed', $html);
        $this->assertStringContainsString('>VOID', $html);
        $this->assertStringContainsString('Napa', $html);                      // the record stays readable forever
    }

    public function test_the_pharmacy_layout_of_the_public_page_hides_the_clinical_detail(): void
    {
        [$rx] = $this->issuedWithContent();

        $html = $this->get('/rx/'.$rx->verification_code.'?layout=pharmacy')->assertOk()->getContent();
        $this->assertStringContainsString('Napa', $html);
        $this->assertStringNotContainsString('Acute upper respiratory infection', $html);
        $this->assertStringNotContainsString('Drink plenty of water', $html);
    }

    public function test_json_is_served_to_api_clients_with_the_snapshot_and_banner(): void
    {
        [$rx] = $this->issuedWithContent();

        $this->getJson('/rx/'.$rx->verification_code)->assertOk()
            ->assertJsonPath('status', 'valid')
            ->assertJsonPath('version', 1)
            ->assertJsonPath('verification_code', $rx->verification_code)
            ->assertJsonPath('snapshot.items.0.brand_name', 'Napa')
            ->assertJsonPath('watermark', 'COPY');
    }

    public function test_the_pdf_download_serves_only_a_valid_version(): void
    {
        Storage::fake('pdfs');
        [$rx] = $this->issuedWithContent();

        $this->get('/rx/'.$rx->verification_code.'/pdf')->assertNotFound();      // nothing rendered yet

        $path = app(PdfStorage::class)->put($rx, '%PDF-1.4 fake');
        $rx->forceFill(['pdf_path' => $path, 'pdf_generated_at' => now()])->save();

        $this->get('/rx/'.$rx->verification_code.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertAudited(AuditAction::Download, $rx, ['event' => 'downloaded', 'surface' => 'verify']);

        app(VoidPrescription::class)->handle($rx->fresh(), 'Cancelled', Actor::user((int) auth('web')->id()));
        $this->get('/rx/'.$rx->verification_code.'/pdf')->assertNotFound();      // a voided copy is never handed out
    }

    public function test_a_code_from_another_tenant_is_not_resolvable(): void
    {
        [$rx] = $this->issuedWithContent();
        $code = (string) $rx->verification_code;

        $this->asTenant('b');
        $this->get('/rx/'.$code)->assertNotFound();
        $this->assertNull(Prescription::query()->where('verification_code', $code)->first());
    }

    public function test_the_public_page_is_rate_limited(): void
    {
        $limiters = collect(app('router')->getRoutes()->getByName('site.prescription.verify')->gatherMiddleware());
        $this->assertTrue($limiters->contains('throttle:rx-verify'), 'the verification page must be throttled — the code is the only credential');
    }
}
