<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Enums\CatalogVersionStatus;
use App\Domain\Catalog\Import\StrengthLabelParser;
use App\Models\Catalog\Brand;
use App\Models\Catalog\CatalogImportIssue;
use App\Models\Catalog\CatalogVersion;
use App\Models\Catalog\Strength;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The dev sample went through the importer (catalog:migrate --seed → catalog:seed → catalog:import) and meets CATALOG.md §3. */
#[Group('catalog')]
final class DevSeedTest extends TestCase
{
    public function test_seed_went_through_the_importer_as_an_applied_version(): void
    {
        $version = CatalogVersion::query()->applied()->first();

        $this->assertNotNull($version);
        $this->assertSame(CatalogVersionStatus::Applied, $version->status);
        $this->assertStringStartsWith('dev.', $version->version);
        $this->assertNotNull($version->checksum_sha256);
        $this->assertSame(75, $version->row_counts['generics']['rows']);
        $this->assertSame(0, CatalogImportIssue::query()->count(), 'the bundled seed must import without issues');
        $this->assertSame(0, DB::connection('catalog')->table('generics')->where('needs_review', true)->count());
    }

    public function test_counts_meet_the_thresholds(): void
    {
        $c = DB::connection('catalog');

        $this->assertGreaterThanOrEqual(40, $c->table('generics')->count());
        $this->assertGreaterThanOrEqual(120, $c->table('brands')->count());
        $this->assertGreaterThanOrEqual(200, $c->table('strengths')->count());
        $this->assertGreaterThanOrEqual(60, $c->table('icd10_codes')->count());
        $this->assertGreaterThanOrEqual(30, $c->table('drug_interactions')->count());
        $this->assertGreaterThanOrEqual(10, $c->table('allergy_classes')->count());
        $this->assertSame(26, $c->table('dosage_forms')->count());
        $this->assertSame(20, $c->table('routes')->count());
        $this->assertGreaterThanOrEqual(60, $c->table('pregnancy_categories')->count());
        $this->assertGreaterThanOrEqual(30, $c->table('renal_cautions')->count());
        $this->assertGreaterThanOrEqual(30, $c->table('hepatic_cautions')->count());
        $this->assertGreaterThanOrEqual(100, $c->table('max_daily_doses')->count());
        $this->assertGreaterThanOrEqual(60, $c->table('drug_information')->count());
    }

    public function test_every_brand_has_an_active_generic_and_every_strength_a_form_and_route(): void
    {
        $c = DB::connection('catalog');

        $this->assertSame(0, $c->table('brands')->leftJoin('generics', 'generics.id', '=', 'brands.generic_id')->whereNull('generics.id')->orWhere('generics.is_active', false)->count());
        $this->assertSame(0, $c->table('strengths')->leftJoin('dosage_forms', 'dosage_forms.id', '=', 'strengths.dosage_form_id')->whereNull('dosage_forms.id')->count());
        $this->assertSame(0, $c->table('strengths')->whereNull('route_id')->count(), 'every seed strength gets its form default route');
        $this->assertSame(0, $c->table('strengths AS s')->join('brands AS b', 'b.id', '=', 's.brand_id')->whereColumn('s.generic_id', '<>', 'b.generic_id')->count());
    }

    public function test_every_strength_label_parses_and_computed_columns_are_filled(): void
    {
        $parser = new StrengthLabelParser;

        Strength::query()->with('dosageForm')->chunk(500, function ($strengths) use ($parser): void {
            foreach ($strengths as $s) {
                $parsed = $parser->tryParse($s->strength_label);
                $this->assertNotNull($parsed, "unparsable seed label {$s->strength_label}");
                $this->assertSame($parsed->label, $s->strength_label);

                if ($parsed->amountUnit !== '%') {
                    $this->assertNotNull($s->strength_mg, "strength_mg missing for {$s->strength_label}");
                }

                if ($parsed->perUnit === 'ml' || $parsed->amountUnit === '%') {
                    $this->assertNotNull($s->per_ml, "per_ml missing for {$s->strength_label}");
                }
            }
        });

        $napa = Strength::query()->whereHas('brand', fn ($q) => $q->where('name', 'Napa'))->where('strength_label', '120 mg/5 ml')->firstOrFail();
        $this->assertSame(24.0, (float) $napa->per_ml);
        $this->assertSame(120.0, (float) $napa->strength_mg);
        $this->assertSame(100.0, (float) $napa->pack_size_value);
        $this->assertSame('ml', $napa->pack_unit);
    }

    public function test_reference_data_shape(): void
    {
        $ranitid = Brand::query()->where('name', 'Ranitid')->firstOrFail();
        $this->assertFalse($ranitid->is_active);
        $this->assertNotNull($ranitid->discontinued_at);

        $c = DB::connection('catalog');
        $this->assertSame(0, $c->table('drug_interactions')->whereColumn('generic_a_id', '>=', 'generic_b_id')->count());

        $penicillins = $c->table('allergy_classes')->where('slug', 'penicillins')->first();
        $cross = json_decode((string) $penicillins->cross_reacts_with, true);
        $cephalosporins = $c->table('allergy_classes')->where('slug', 'cephalosporins')->value('id');
        $this->assertSame((int) $cephalosporins, $cross[0]['allergy_class_id']);
        $this->assertSame(10, $cross[0]['probability_pct']);

        $ibuprofen = $c->table('generics')->where('slug', 'ibuprofen')->value('id');
        $rows = $c->table('pregnancy_categories')->where('generic_id', $ibuprofen)->orderByRaw('trimester NULLS FIRST')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['C', 'D'], $rows->pluck('category')->all());
        $this->assertSame('safe', $rows[0]->lactation);                                         // L1 → safe

        $metformin = $c->table('generics')->where('slug', 'metformin')->value('id');
        $this->assertSame([30, 45], $c->table('renal_cautions')->where('generic_id', $metformin)->orderBy('egfr_below')->pluck('egfr_below')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(1, $c->table('max_daily_doses')->where('generic_id', $metformin)->where('population', 'pediatric')->where('min_age_months', 120)->count());

        $e119 = $c->table('icd10_codes')->where('code', 'E11.9')->first();
        $this->assertSame('E11', $e119->parent_code);
        $this->assertContains('সুগার', json_decode((string) $e119->aliases, true));

        $amoxiclav = $c->table('generics')->where('slug', 'amoxicillin-clavulanic-acid')->first();
        $components = json_decode((string) $amoxiclav->components, true);
        $this->assertSame((int) $c->table('generics')->where('slug', 'amoxicillin')->value('id'), $components[0]['generic_id']);

        $this->assertSame('paracetamol', $c->table('drug_information')->join('generics', 'generics.id', '=', 'drug_information.generic_id')->where('generics.slug', 'paracetamol')->value('public_slug'));
    }
}
