<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Catalog\Services\DrugInformationLookup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The read API the safety pipeline consumes (PRESCRIPTION.md §5.6). */
#[Group('catalog')]
final class CatalogCacheTest extends TestCase
{
    public function test_row_lookups_are_version_keyed_and_cache_negatives(): void
    {
        $cache = app(CatalogCache::class);
        $version = $cache->currentVersion();
        $this->assertStringStartsWith('dev.', $version);

        $paracetamol = (int) DB::connection('catalog')->table('generics')->where('slug', 'paracetamol')->value('id');
        $row = $cache->generic($paracetamol);

        $this->assertSame('Paracetamol', $row['name']);
        $this->assertTrue($row['is_active']);
        $this->assertContains('Acetaminophen', $row['aliases']);
        $this->assertSame($row, Cache::get("catalog:{$version}:generic:{$paracetamol}"));

        $this->assertNull($cache->generic(999999));
        $this->assertSame('__none__', Cache::get("catalog:{$version}:generic:999999"));

        $batch = $cache->generics([$paracetamol, 999999]);
        $this->assertSame([$paracetamol], array_keys($batch));

        $form = $cache->dosageForm((int) DB::connection('catalog')->table('dosage_forms')->where('code', 'tab')->value('id'));
        $this->assertSame('tab', $form['code']);
        $this->assertSame('tab', $form['default_unit']);
        $this->assertSame('E11.9', $cache->icd10('E11.9')['code']);
        $this->assertNull($cache->icd10('Q99'));
    }

    public function test_safety_lookups(): void
    {
        $cache = app(CatalogCache::class);
        $c = DB::connection('catalog');
        $warfarin = (int) $c->table('generics')->where('slug', 'warfarin')->value('id');
        $aspirin = (int) $c->table('generics')->where('slug', 'aspirin')->value('id');
        $paracetamol = (int) $c->table('generics')->where('slug', 'paracetamol')->value('id');
        $amoxicillin = (int) $c->table('generics')->where('slug', 'amoxicillin')->value('id');

        $this->assertSame('major', $cache->interaction($warfarin, $aspirin)['severity']);
        $this->assertSame('major', $cache->interaction($aspirin, $warfarin)['severity']);                 // either order
        $this->assertNull($cache->interaction($paracetamol, $aspirin));
        $this->assertCount(1, $cache->interactions([[$aspirin, $warfarin], [$paracetamol, $aspirin], [$aspirin, $aspirin]]));

        $penicillins = (int) $c->table('allergy_classes')->where('slug', 'penicillins')->value('id');
        $this->assertContains($amoxicillin, $cache->allergyClassGenerics($penicillins));
        $this->assertSame([$penicillins], $cache->genericAllergyClasses($amoxicillin));
        $this->assertSame([], $cache->genericAllergyClasses($paracetamol));

        $doses = $cache->maxDoses($paracetamol);
        $this->assertCount(3, $doses);
        $this->assertSame(['adult', 'elderly', 'pediatric'], collect($doses)->pluck('population')->sort()->values()->all());
        $this->assertSame('B', $cache->pregnancy($paracetamol)[0]['category']);
        $this->assertSame('adjust_dose', $cache->hepatic($paracetamol)[0]['level']);
        $this->assertSame([], $cache->renal($paracetamol));

        $metformin = (int) $c->table('generics')->where('slug', 'metformin')->value('id');
        $this->assertSame([45, 30], collect($cache->renal($metformin))->pluck('egfr_below')->all());   // most specific threshold first

        $info = app(DrugInformationLookup::class)->bySlug('paracetamol');
        $this->assertTrue($info['published']);
        $this->assertSame('Paracetamol', $info['generic_name']);
        $this->assertStringContainsString('liver', $info['side_effects']);
        $this->assertStringContainsString('প্যারাসিটামল', $info['patient_advice_bn']);
        $this->assertNull(app(DrugInformationLookup::class)->bySlug('nope'));
        $this->assertSame('paracetamol', app(DrugInformationLookup::class)->slugForGeneric($paracetamol));
    }

    public function test_fake_seeds_from_arrays_and_bump_changes_prefix(): void
    {
        $fake = CatalogCache::fake(['generics' => [17 => ['id' => 17, 'name' => 'Fakeamol', 'is_active' => true]], 'drug_interactions' => ['1:2' => ['severity' => 'minor']]]);

        $this->assertSame($fake, app(CatalogCache::class));
        $this->assertSame('Fakeamol', app(CatalogCache::class)->generic(17)['name']);
        $this->assertNull(app(CatalogCache::class)->generic(18));
        $this->assertSame('minor', app(CatalogCache::class)->interaction(2, 1)['severity']);
        $this->assertSame('fake', app(CatalogCache::class)->currentVersion());

        app()->forgetInstance(CatalogCache::class);
        $real = app(CatalogCache::class);
        $version = $real->currentVersion();
        $this->assertSame($version, Cache::get('catalog:current_version'));
        $real->bumpVersion();
        $this->assertNull(Cache::get('catalog:current_version'));
        $this->assertSame($version, $real->currentVersion());                                             // re-read from the applied row
    }
}
