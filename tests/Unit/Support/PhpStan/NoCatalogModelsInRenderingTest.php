<?php

declare(strict_types=1);

namespace Tests\Unit\Support\PhpStan;

use App\Support\PhpStan\NoCatalogModelsInRendering;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;

/**
 * BRIEF §8 / ARCHITECTURE §8.3 — the rendering namespace may not reach the catalog.
 *
 * `#[WithoutErrorHandler]`: PHPStan's file cache reads with `@include`, and PHPUnit's error handler would otherwise
 * turn that suppressed warning into a test warning on the first (cold cache) run.
 *
 * @extends RuleTestCase<NoCatalogModelsInRendering>
 */
final class NoCatalogModelsInRenderingTest extends RuleTestCase
{
    private const TIP = 'BRIEF §8 / ARCHITECTURE §8.3: the frozen PrescriptionSnapshot is the only render source, so a renamed or deactivated catalog row can never change an already issued prescription. Read the value off the snapshot instead.';

    #[WithoutErrorHandler]
    public function test_it_reports_catalog_classes_and_the_catalog_connection_inside_the_render_namespace(): void
    {
        $this->analyse([__DIR__.'/data/catalog-in-rendering.inc'], [
            ['Prescription rendering must not reference the catalog: App\Models\Catalog\Generic is used inside App\Domain\Prescription\Render.', 7, self::TIP],
            ['Prescription rendering must not reference the catalog: App\Models\Catalog\Generic is used inside App\Domain\Prescription\Render.', 12, self::TIP],
            ['Prescription rendering must not reference the catalog: App\Models\Catalog\Brand is used inside App\Domain\Prescription\Render.', 19, self::TIP],
            ['Prescription rendering must not reference the catalog: App\Models\Catalog\Strength is used inside App\Domain\Prescription\Render.', 24, self::TIP],
            ['Prescription rendering must not reference the catalog: App\Models\Catalog\DosageForm is used inside App\Domain\Prescription\Render.', 29, self::TIP],
            ["Prescription rendering must not use the 'catalog' database connection inside App\Domain\Prescription\Render.", 34, self::TIP],
            ["Prescription rendering must not use the 'catalog' database connection inside App\Domain\Prescription\Render.", 39, self::TIP],
        ]);
    }

    #[WithoutErrorHandler]
    public function test_it_leaves_the_catalog_alone_outside_the_render_namespace(): void
    {
        $this->analyse([__DIR__.'/data/catalog-outside-rendering.inc'], []);
    }

    protected function getRule(): Rule
    {
        return new NoCatalogModelsInRendering;
    }
}
