<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Domain\Prescription\Render\PrescriptionRenderer;
use App\Domain\Prescription\Render\RenderOptions;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BRIEF §8 / PRESCRIPTION.md invariant I6: "No prescription rendering path joins live to the `catalog` database."
 *
 * These two tests are the whole reason `prescriptions.snapshot` exists. The first proves the mechanism (zero
 * queries on the catalog connection while rendering); the second proves the consequence that actually matters to
 * a patient — the prescription in their hand still prints exactly as issued after the shared drug catalog has
 * been renamed, re-priced and deactivated underneath it.
 */
final class SnapshotRenderingTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /**
     * @param  \Closure(): mixed  $callback
     * @return list<string> every statement that reached a catalog connection while $callback ran
     */
    private function catalogQueries(\Closure $callback): array
    {
        $seen = [];
        DB::listen(function (QueryExecuted $query) use (&$seen): void {
            if (str_starts_with($query->connectionName, 'catalog')) {
                $seen[] = $query->connectionName.': '.$query->sql;
            }
        });

        $callback();

        return $seen;
    }

    public function test_rendering_an_issued_prescription_executes_zero_catalog_queries(): void
    {
        [$rx] = $this->issuedWithContent();
        $renderer = app(PrescriptionRenderer::class);
        $snapshot = $rx->snapshot;
        $this->assertNotNull($snapshot);

        $html = '';
        $queries = $this->catalogQueries(function () use ($renderer, $snapshot, &$html): void {
            foreach (['full', 'pharmacy'] as $layout) {
                foreach (['bn', 'en', 'both'] as $language) {
                    $html .= $renderer->render($snapshot, RenderOptions::fromPad($snapshot->pad(), purpose: 'pdf', language: $language, layout: $layout));
                }
            }
        });

        $this->assertSame([], $queries, 'the renderer reached the catalog database');
        $this->assertStringContainsString('Napa', $html);
    }

    public function test_the_print_pdf_and_public_verification_routes_are_catalog_free_end_to_end(): void
    {
        [$rx] = $this->issuedWithContent();
        $code = (string) $rx->verification_code;

        $queries = $this->catalogQueries(function () use ($rx, $code): void {
            $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk();
            $this->get('/panel/prescriptions/'.$rx->public_id.'/pharmacy')->assertOk();
            $this->get('/panel/prescriptions/'.$rx->public_id)->assertOk();
            $this->get('/rx/'.$code)->assertOk();
        });

        $this->assertSame([], $queries, 'a rendering route reached the catalog database');
    }

    public function test_a_prescription_renders_identically_after_its_catalog_rows_are_renamed_and_deactivated(): void
    {
        [$rx] = $this->issuedWithContent();
        $snapshot = $rx->snapshot;
        $this->assertNotNull($snapshot);
        $options = RenderOptions::fromPad($snapshot->pad(), purpose: 'pdf');
        $before = app(PrescriptionRenderer::class)->render($snapshot, $options);

        $item = $rx->items()->firstOrFail();
        $this->assertNotNull($item->brand_id);
        $catalog = DB::connection('catalog');
        $original = [
            'brand' => (array) $catalog->table('brands')->where('id', $item->brand_id)->first(),
            'generic' => (array) $catalog->table('generics')->where('id', $item->generic_id)->first(),
            'strength' => (array) $catalog->table('strengths')->where('id', $item->strength_id)->first(),
        ];

        try {
            // The shared catalog moves on: the brand is renamed, the presentation is withdrawn, the molecule is
            // re-spelled. None of it may reach a prescription that was issued before the change.
            app(CatalogWriteContext::class)->run(function () use ($item): void {
                DB::connection('catalog_admin')->table('brands')->where('id', $item->brand_id)->update(['name' => 'RENAMED-BRAND']);
                DB::connection('catalog_admin')->table('generics')->where('id', $item->generic_id)->update(['name' => 'RENAMED-GENERIC']);
                DB::connection('catalog_admin')->table('strengths')->where('id', $item->strength_id)->update(['is_active' => false]);
            }, 'test: catalog drift');
            app(CatalogCache::class)->bumpVersion();

            $fresh = $rx->fresh();
            $this->assertNotNull($fresh?->snapshot);
            $after = app(PrescriptionRenderer::class)->render($fresh->snapshot, $options);

            $this->assertSame($before, $after, 'the rendered prescription changed when the catalog changed');
            $this->assertStringContainsString('Napa', $after);
            $this->assertStringContainsString('Paracetamol', $after);
            $this->assertStringNotContainsString('RENAMED-BRAND', $after);
            $this->assertStringNotContainsString('RENAMED-GENERIC', $after);

            // And the served route says the same thing.
            $served = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();
            $this->assertStringContainsString('Napa', $served);
            $this->assertStringNotContainsString('RENAMED-BRAND', $served);
        } finally {
            // catalog_admin commits outside the test transaction, so the seed is restored explicitly.
            app(CatalogWriteContext::class)->run(function () use ($item, $original): void {
                DB::connection('catalog_admin')->table('brands')->where('id', $item->brand_id)->update(['name' => $original['brand']['name']]);
                DB::connection('catalog_admin')->table('generics')->where('id', $item->generic_id)->update(['name' => $original['generic']['name']]);
                DB::connection('catalog_admin')->table('strengths')->where('id', $item->strength_id)->update(['is_active' => $original['strength']['is_active']]);
            }, 'test: restore catalog');
            app(CatalogCache::class)->bumpVersion();
        }
    }
}
