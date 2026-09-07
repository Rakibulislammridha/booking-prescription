<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Data\DrugSearchQuery;
use App\Domain\Catalog\Search\CatalogSearchIndexer;
use App\Domain\Catalog\Search\DrugSearchService;
use App\Domain\Catalog\Search\MeilisearchIndexes;
use App\Models\Tenant\CustomBrand;
use Illuminate\Support\Facades\DB;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** t{tenant_id}_custom_brands: tenant-prefixed uid, shouldBeSearchable rules, merged federated list marks source = custom. */
#[Group('catalog')]
#[Group('search')]
final class CustomBrandIndexTest extends TestCase
{
    private static bool $built = false;

    private static string $host = '';

    private static ?string $key = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'meilisearch', 'scout.queue' => false]);
        self::$host = (string) config('scout.meilisearch.host');
        self::$key = config('scout.meilisearch.key');

        try {
            app(Client::class)->health();
        } catch (\Throwable) {
            $this->markTestSkipped('Meilisearch is not reachable');
        }

        if (self::$built === false) {
            app(CatalogSearchIndexer::class)->rebuild('catalog_drugs');
            app(MeilisearchIndexes::class)->ensureTenantIndexes($this->tenant('a'));
            self::$built = true;
        }

        $this->asTenant('a');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$built) {
            $client = new Client(self::$host, self::$key);                    // the app is gone by now: no config() here
            $prefix = (string) getenv('SCOUT_PREFIX');

            foreach ([$prefix.'catalog_drugs', $prefix.'catalog_drugs_next', $prefix.'t9001_custom_brands', $prefix.'t9001_patients'] as $uid) {
                try {
                    $client->waitForTask($client->deleteIndex($uid)['taskUid'], 60_000, 100);
                } catch (ApiException) {
                }
            }

            self::$built = false;
        }

        parent::tearDownAfterClass();
    }

    public function test_index_name_settings_and_searchability_rules(): void
    {
        $uid = (new CustomBrand)->searchableAs();
        $this->assertSame(config('scout.prefix').'t9001_custom_brands', $uid);

        $settings = app(Client::class)->index($uid)->getSettings();
        $this->assertSame(config('catalog.search.custom_brands.rankingRules'), $settings['rankingRules']);
        $this->assertContains('review_status', $settings['filterableAttributes']);

        $paracetamol = (int) DB::connection('catalog')->table('generics')->where('slug', 'paracetamol')->value('id');
        $this->assertTrue(CustomBrand::factory()->make(['generic_id' => $paracetamol])->shouldBeSearchable());
        $this->assertFalse(CustomBrand::factory()->rejected()->make(['generic_id' => $paracetamol])->shouldBeSearchable());
        $this->assertFalse(CustomBrand::factory()->inactive()->make(['generic_id' => $paracetamol])->shouldBeSearchable());
    }

    public function test_custom_brand_is_merged_into_the_federated_list_and_flagged(): void
    {
        $paracetamol = (int) DB::connection('catalog')->table('generics')->where('slug', 'paracetamol')->value('id');
        $tab = DB::connection('catalog')->table('dosage_forms')->where('code', 'tab')->first();
        $brand = CustomBrand::factory()->create(['brand_name' => 'Zzparamol', 'generic_id' => $paracetamol, 'generic_name' => 'Paracetamol', 'strength' => '500 mg', 'dosage_form_id' => $tab->id, 'form' => 'Tablet']);

        $client = app(Client::class);
        $doc = $this->waitForDocument($client, (new CustomBrand)->searchableAs(), 'c'.$brand->id);
        $this->assertSame('custom', $doc['source']);
        $this->assertSame('Zzparamol 500 mg Tab', $doc['label']);
        $this->assertSame('tab', $doc['form_code']);
        $this->assertSame(500, (int) $doc['strength_mg']);

        $result = app(DrugSearchService::class)->search(new DrugSearchQuery(q: 'zzparam', limit: 5));
        $this->assertSame('meilisearch', $result->engine);
        $hit = collect($result->hits)->firstWhere('id', 'c'.$brand->id);
        $this->assertNotNull($hit);
        $this->assertSame('custom', $hit['source']);
        $this->assertSame($brand->id, $hit['custom_brand_id']);
        $this->assertSame('pending', $hit['review_status']);
        $this->assertNull($hit['brand_id']);

        $merged = app(DrugSearchService::class)->search(new DrugSearchQuery(q: 'paracetamol', limit: 40));
        $this->assertContains('custom', collect($merged->hits)->pluck('source')->unique()->all());
        $this->assertContains('master', collect($merged->hits)->pluck('source')->unique()->all());

        $brand->forceFill(['review_status' => 'rejected', 'reviewed_at' => now()])->save();
        $this->assertNull($this->waitForDocument($client, (new CustomBrand)->searchableAs(), 'c'.$brand->id, expectGone: true), 'rejected brand leaves the index');
    }

    /** @return array<string, mixed>|null */
    private function waitForDocument(Client $client, string $uid, string $id, bool $expectGone = false): ?array
    {
        for ($i = 0; $i < 40; $i++) {
            try {
                $doc = $client->index($uid)->getDocument($id);

                if (! $expectGone) {
                    return $doc;
                }
            } catch (ApiException $e) {
                if ($expectGone && $e->errorCode === 'document_not_found') {
                    return null;
                }
            }

            usleep(100_000);
        }

        $this->fail("document {$id} ".($expectGone ? 'still present' : 'never indexed')." in {$uid}");
    }
}
