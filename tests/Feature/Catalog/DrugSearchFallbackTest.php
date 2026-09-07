<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Data\DrugSearchQuery;
use App\Domain\Catalog\Search\DrugSearchService;
use App\Domain\Catalog\Search\Icd10SearchService;
use App\Models\Tenant\CustomBrand;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** With SCOUT_DRIVER=null the services answer from Postgres with the same document shapes (dev / CI without Meilisearch). */
#[Group('catalog')]
final class DrugSearchFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        $this->asTenant('a');
    }

    public function test_merged_list_ranks_napa_first_and_flags_custom_brands(): void
    {
        $paracetamol = (int) DB::connection('catalog')->table('generics')->where('slug', 'paracetamol')->value('id');
        CustomBrand::factory()->create(['brand_name' => 'Napaxx', 'generic_id' => $paracetamol, 'generic_name' => 'Paracetamol', 'strength' => '500 mg']);
        CustomBrand::factory()->rejected()->create(['brand_name' => 'Napbad', 'generic_id' => $paracetamol, 'generic_name' => 'Paracetamol']);

        $result = app(DrugSearchService::class)->search(new DrugSearchQuery(q: 'nap', limit: 12));

        $this->assertSame('database', $result->engine);
        $first = $result->hits[0];
        $this->assertSame('Napa', $first['brand_name']);
        $this->assertSame('master', $first['source']);
        $this->assertSame('presentation', $first['doc_type']);
        $this->assertSame('Napa 500 mg Tab', $first['label']);
        $this->assertSame('paracetamol', $first['info_slug']);
        $this->assertSame(500.0, $first['strength_mg']);
        $this->assertSame('tab', $first['form_code']);
        $this->assertSame('po', $first['route_code']);
        $this->assertSame(0, $first['usage']);
        $this->assertFalse($first['fav_for_dx']);
        $this->assertArrayHasKey('score', $first);

        $custom = collect($result->hits)->firstWhere('source', 'custom');
        $this->assertNotNull($custom, 'custom brand merged into the list');
        $this->assertSame('Napaxx', $custom['brand_name']);
        $this->assertStringStartsWith('c', $custom['id']);
        $this->assertSame('pending', $custom['review_status']);
        $this->assertSame(500.0, $custom['strength_mg']);
        $this->assertNull(collect($result->hits)->firstWhere('brand_name', 'Napbad'), 'rejected custom brands are excluded');
        $this->assertNull(collect($result->hits)->firstWhere('brand_name', 'Ranitid'));

        $filtered = app(DrugSearchService::class)->search(new DrugSearchQuery(q: 'nap 665'));
        $this->assertSame('nap', $filtered->q);
        $this->assertSame(['Napa 665 mg XR Tab'], collect($filtered->hits)->where('doc_type', 'presentation')->pluck('label')->all());

        $generic = app(DrugSearchService::class)->search(new DrugSearchQuery(q: 'paracet', limit: 5));
        $this->assertContains('generic', collect($generic->hits)->pluck('doc_type')->all(), 'prescribe-by-generic row keeps a slot');
        $this->assertSame([], app(DrugSearchService::class)->search(new DrugSearchQuery(q: '   '))->hits);
    }

    public function test_icd10_fallback_and_billable_filter(): void
    {
        $result = app(Icd10SearchService::class)->search('sugar');
        $this->assertSame('E11.9', $result['hits'][0]['code']);
        $this->assertSame('database', $result['engine']);
        $this->assertSame('E11.9', app(Icd10SearchService::class)->search('সুগার')['hits'][0]['code']);
        $this->assertSame('E11.9', app(Icd10SearchService::class)->search('e11.9')['hits'][0]['code']);
        $this->assertNotContains('E11', collect($result['hits'])->pluck('code')->all(), 'categories are hidden by default');
        $this->assertContains('E11', collect(app(Icd10SearchService::class)->search('diabetes', billableOnly: false)['hits'])->pluck('code')->all());
    }

    public function test_api_endpoints_need_a_staff_session_and_return_the_documented_shapes(): void
    {
        $this->getJson('/api/catalog/drugs?q=nap')->assertUnauthorized();

        $this->actingAsDoctor();
        $this->getJson('/api/catalog/drugs?q=nap&limit=3')->assertOk()->assertHeader('Cache-Control', 'max-age=30, private')
            ->assertJsonPath('q', 'nap')->assertJsonPath('hits.0.brand_name', 'Napa')->assertJsonPath('hits.0.id', fn ($id) => str_starts_with($id, 's'))
            ->assertJsonCount(3, 'hits')->assertJsonStructure(['q', 'took_ms', 'engine', 'hits' => [['id', 'source', 'doc_type', 'label', 'generic_id', 'generic_name', 'brand_id', 'custom_brand_id',
                'strength_id', 'strength_label', 'strength_mg', 'per_ml', 'dosage_form_id', 'form', 'form_code', 'default_unit', 'route_id', 'route', 'route_code', 'pack_size_value', 'pack_unit', 'info_slug', 'usage', 'fav_for_dx', 'score', 'last_shorthand']]]);
        $this->getJson('/api/catalog/drugs')->assertStatus(422);
        $this->getJson('/api/catalog/icd10?q=sugar')->assertOk()->assertJsonPath('hits.0.code', 'E11.9')->assertJsonStructure(['hits' => [['code', 'title', 'title_bn', 'chapter', 'is_billable', 'usage']]]);
        $this->getJson('/api/catalog/generics?q=para')->assertOk()->assertJsonPath('hits.0.name', 'Paracetamol')->assertJsonStructure(['hits' => [['id', 'name', 'name_bn', 'therapeutic_class', 'aliases']]]);
        $this->getJson('/api/catalog/vocabulary')->assertOk()->assertJsonCount(26, 'forms')->assertJsonCount(20, 'routes')->assertJsonPath('forms.0.code', 'tab');
    }
}
