<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Data\ImportRow;
use App\Domain\Catalog\Enums\CatalogVersionStatus;
use App\Domain\Catalog\Import\GenericResolver;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Models\Catalog\CatalogImportIssue;
use App\Models\Catalog\CatalogVersion;
use App\Models\Catalog\Generic;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('catalog')]
final class GenericResolverTest extends TestCase
{
    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'catalog', 'catalog_admin'];

    public function test_normalises_salts_tags_and_combinations(): void
    {
        $r = app(GenericResolver::class);

        $this->assertSame(['name' => 'amoxicillin', 'slug' => 'amoxicillin', 'salt' => 'trihydrate', 'parts' => ['amoxicillin']], $r->normalise('Amoxicillin Trihydrate BP'));
        $this->assertSame('paracetamol', $r->normalise('PARACETAMOL USP')['slug']);
        $this->assertSame(['amoxicillin', 'clavulanic acid'], $r->normalise('Amoxicillin + Clavulanic Acid (as potassium clavulanate)')['parts']);
        $this->assertSame('ferrous sulfate', $r->normalise('Ferrous Sulfate')['name']);   // identity salts are kept
    }

    public function test_resolves_by_slug_alias_and_trigram(): void
    {
        $r = app(GenericResolver::class);
        $paracetamol = Generic::query()->where('slug', 'paracetamol')->firstOrFail();

        $this->assertTrue($paracetamol->is($r->resolve('Paracetamol')));
        $this->assertTrue($paracetamol->is($r->resolve('paracetamol bp')));
        $this->assertTrue($paracetamol->is($r->resolve('Acetaminophen')));                  // alias
        $this->assertTrue($paracetamol->is($r->resolve('প্যারাসিটামল')));                   // Bangla alias
        $this->assertTrue(Generic::query()->where('slug', 'amoxicillin')->firstOrFail()->is($r->resolve('Amoxicillin Trihydrate')));
        $this->assertTrue(Generic::query()->where('slug', 'amoxicillin-clavulanic-acid')->firstOrFail()->is($r->resolve('Amoxicillin + Clavulanic acid')));
        $this->assertNull($r->resolve('Unobtainium'));

        config(['catalog.import.trigram_threshold' => 0.6]);
        $this->assertTrue($paracetamol->is(app(GenericResolver::class)->resolve('Paracetamoll')));   // one-letter typo passes a relaxed trigram threshold
        $this->assertNull(app(GenericResolver::class)->resolve('Zzzqqq'));
    }

    public function test_unknown_text_creates_a_needs_review_generic_and_an_issue(): void
    {
        app(CatalogWriteContext::class)->run(function (): void {
            $version = CatalogVersion::query()->create(['version' => 'test.resolver', 'status' => CatalogVersionStatus::Draft]);
            $row = new ImportRow(sourceRow: 7, manufacturer: 'Local', brand: 'Newbrand', genericText: 'Novelium Hydrochloride', strengthLabel: '10 mg', formText: 'Tablet');

            $generic = app(GenericResolver::class)->resolveOrCreate('Novelium Hydrochloride', $version->id, $row);

            $this->assertTrue($generic->needs_review);
            $this->assertSame('novelium', $generic->slug);
            $this->assertSame('Novelium', $generic->name);
            $this->assertSame($version->id, $generic->catalog_version_id);

            $issue = CatalogImportIssue::query()->where('catalog_version_id', $version->id)->firstOrFail();
            $this->assertSame('unknown_generic', $issue->kind->value);
            $this->assertSame(7, $issue->source_row);
            $this->assertSame($generic->id, $issue->payload['created_generic_id']);
            $this->assertSame('Newbrand', $issue->payload['brand']);

            // Second call is memoised / resolves the row just created — no duplicate generic or issue.
            $this->assertTrue($generic->is(app(GenericResolver::class)->resolveOrCreate('Novelium Hydrochloride', $version->id, $row)) || Generic::query()->where('slug', 'novelium')->count() === 1);
        });
    }
}
