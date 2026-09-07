<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Catalog\Services\CatalogWriteContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRESCRIPTION.md §7.8 — GET /drug/{slug}, the "click here for more information" URL printed under every Rx line.
 * A catalog read (not a prescription render), so it may query the catalog; it is public, so it must be safe with
 * an unpublished row and readable on a phone in Bangla.
 */
final class DrugInfoPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_a_published_drug_page_shows_both_languages_with_a_toggle(): void
    {
        $html = $this->get('/drug/paracetamol')->assertOk()->getContent();

        $this->assertStringContainsString('Paracetamol', $html);
        $this->assertStringContainsString('জ্বর', $html);                        // Bangla indications from the catalog
        $this->assertStringContainsString('data-pane="bn"', $html);
        $this->assertStringContainsString('data-pane="en"', $html);
        $this->assertStringContainsString('data-lang="en"', $html);
        $this->assertStringContainsString('not medical advice', $html);          // the page must not read as a prescription
        $this->assertStringContainsString('noindex', $html);
    }

    public function test_an_unknown_or_malformed_slug_is_a_404_or_a_safe_placeholder(): void
    {
        $this->get('/drug/NOT_A_SLUG')->assertNotFound();
        $this->get('/drug/../../etc/passwd')->assertNotFound();

        $html = $this->get('/drug/no-such-molecule')->assertOk()->getContent();
        $this->assertStringContainsString('not published yet', $html);
        $this->assertStringContainsString('ডাক্তার', $html);
    }

    public function test_an_unpublished_row_never_leaks_its_draft_text(): void
    {
        $row = DB::connection('catalog')->table('drug_information')->where('public_slug', 'ibuprofen')->first();
        $this->assertNotNull($row);

        try {
            app(CatalogWriteContext::class)->run(function (): void {
                DB::connection('catalog_admin')->table('drug_information')->where('public_slug', 'ibuprofen')
                    ->update(['published_at' => null, 'side_effects' => 'UNPUBLISHED-DRAFT-TEXT']);
            }, 'test: unpublish');
            app(CatalogCache::class)->bumpVersion();

            $html = $this->get('/drug/ibuprofen')->assertOk()->getContent();
            $this->assertStringContainsString('not published yet', $html);
            $this->assertStringNotContainsString('UNPUBLISHED-DRAFT-TEXT', $html);
        } finally {
            app(CatalogWriteContext::class)->run(function () use ($row): void {
                DB::connection('catalog_admin')->table('drug_information')->where('public_slug', 'ibuprofen')
                    ->update(['published_at' => $row->published_at, 'side_effects' => $row->side_effects]);
            }, 'test: restore');
            app(CatalogCache::class)->bumpVersion();
        }
    }
}
