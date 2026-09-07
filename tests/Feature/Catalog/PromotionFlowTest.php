<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\CreateCustomBrand;
use App\Domain\Catalog\Data\CustomBrandData;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Domain\Shared\Actor;
use App\Models\Central\CustomBrandPromotion;
use App\Models\Tenant\CustomBrand;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * create → pending queue row → approve (create / map) → master rows under a promotion version, tenant flags promoted
 * with master ids, other tenants' identical pending brands auto-mapped; reject path. catalog_admin is transacted.
 */
#[Group('catalog')]
final class PromotionFlowTest extends TestCase
{
    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'catalog', 'catalog_admin'];

    public function test_approve_with_create_mode_writes_master_rows_and_flags_the_tenant_row(): void
    {
        $paracetamol = (int) DB::connection('catalog')->table('generics')->where('slug', 'paracetamol')->value('id');
        $tab = (int) DB::connection('catalog')->table('dosage_forms')->where('code', 'tab')->value('id');

        $this->asTenant('a');
        $this->actingAsDoctor();
        $brandA = app(CreateCustomBrand::class)->handle(new CustomBrandData(brandName: 'Paramax', genericId: $paracetamol, manufacturer: 'Local Pharma', strength: '500 mg', dosageFormId: $tab), Actor::system());

        $this->asTenant('b');
        $this->actingAsDoctor();
        $brandB = app(CreateCustomBrand::class)->handle(new CustomBrandData(brandName: 'paramax', genericId: $paracetamol, strength: '500 mg', dosageFormId: $tab), Actor::system());

        $promotion = CustomBrandPromotion::query()->where('tenant_id', 9001)->where('custom_brand_id', $brandA->id)->firstOrFail();
        $this->assertSame(2, CustomBrandPromotion::query()->where('status', 'pending')->count());

        $this->actingAsSuper();
        $this->getJson('/catalog/promotions?status=pending')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/catalog/promotions/{$promotion->public_id}")->assertOk()->assertJsonPath('brand_name', 'Paramax')->assertJsonPath('use_count', 0)
            ->assertJsonStructure(['similar_master_brands']);

        $response = $this->postJson("/catalog/promotions/{$promotion->public_id}/approve", ['mode' => 'create', 'presentations' => 'tab:500 mg:10x10; tab:650 mg:10x10', 'note' => 'ok'])->assertOk();
        $master = $response->json('master');

        // Master rows exist (read through the admin connection: they roll back with the test).
        $brand = (array) DB::connection('catalog_admin')->table('brands')->find($master['brand_id']);
        $this->assertSame('Paramax', $brand['name']);
        $this->assertSame('paramax-paracetamol', $brand['slug']);
        $this->assertSame('Local Pharma', $brand['manufacturer']);
        $this->assertSame($paracetamol, (int) $brand['generic_id']);
        $this->assertSame(2, DB::connection('catalog_admin')->table('strengths')->where('brand_id', $brand['id'])->count());
        $this->assertSame('500 mg', DB::connection('catalog_admin')->table('strengths')->where('id', $master['strength_id'])->value('strength_label'));
        $version = (array) DB::connection('catalog_admin')->table('catalog_versions')->find($master['catalog_version_id']);
        $this->assertStringStartsWith('promo.', (string) $version['version']);
        $this->assertStringContainsString('promotion', (string) $version['notes']);
        $this->assertFalse(app(CatalogWriteContext::class)->isOpen());

        $this->assertSame('promoted', $promotion->refresh()->status);
        $this->assertSame('create', $promotion->getAttribute('decision')['mode']);
        $this->assertNotNull($promotion->getAttribute('reviewed_at'));

        // Tenant A row carries the master ids; tenant B's identical pending brand was auto-mapped.
        Tenancy::run($this->tenant('a'), function () use ($brandA, $master): void {
            $row = CustomBrand::query()->findOrFail($brandA->id);
            $this->assertSame('promoted', $row->review_status->value);
            $this->assertTrue($row->promoted_to_master);
            $this->assertSame($master['brand_id'], $row->master_brand_id);
            $this->assertSame($master['strength_id'], $row->master_strength_id);
            $this->assertSame($master['brand_id'], $row->toSearchableArray()['brand_id']);
        });
        Tenancy::run($this->tenant('b'), function () use ($brandB, $master): void {
            $row = CustomBrand::query()->findOrFail($brandB->id);
            $this->assertSame('promoted', $row->review_status->value);
            $this->assertSame($master['brand_id'], $row->master_brand_id);
        });
        $this->assertSame('map', CustomBrandPromotion::query()->where('tenant_id', 9002)->firstOrFail()->getAttribute('decision')['mode']);
        $this->assertSame(0, CustomBrandPromotion::query()->where('status', 'pending')->count());

        // Reviewing again is refused with a dotted code.
        $this->postJson("/catalog/promotions/{$promotion->public_id}/reject", ['reason' => 'late'])->assertStatus(409)->assertJsonPath('code', 'catalog.promotion_not_pending');
    }

    public function test_map_mode_links_an_existing_master_brand_and_reject_keeps_the_brand_usable(): void
    {
        $paracetamol = (int) DB::connection('catalog')->table('generics')->where('slug', 'paracetamol')->value('id');
        $napa = (object) DB::connection('catalog')->table('brands')->where('name', 'Napa')->first();

        $this->asTenant('a');
        $this->actingAsDoctor();
        $mapped = app(CreateCustomBrand::class)->handle(new CustomBrandData(brandName: 'Napa Extra', genericId: $paracetamol, strength: '500 mg'), Actor::system());
        $rejected = app(CreateCustomBrand::class)->handle(new CustomBrandData(brandName: 'Dubious', genericId: $paracetamol), Actor::system());

        $pMapped = CustomBrandPromotion::query()->where('custom_brand_id', $mapped->id)->firstOrFail();
        $pRejected = CustomBrandPromotion::query()->where('custom_brand_id', $rejected->id)->firstOrFail();

        $this->actingAsSuper();
        $this->postJson("/catalog/promotions/{$pMapped->public_id}/approve", ['mode' => 'map'])->assertStatus(422)->assertJsonValidationErrors(['brand_id']);
        $this->postJson("/catalog/promotions/{$pMapped->public_id}/approve", ['mode' => 'map', 'brand_id' => $napa->id])->assertOk()->assertJsonPath('master.brand_id', $napa->id);
        $this->assertSame(6, DB::connection('catalog_admin')->table('strengths')->where('brand_id', $napa->id)->count(), '500 mg Tab already exists: mapped, not duplicated');

        $this->postJson("/catalog/promotions/{$pRejected->public_id}/reject", ['reason' => 'not a registered product'])->assertOk()->assertJsonPath('promotion.status', 'rejected');
        $this->assertSame('not a registered product', $pRejected->refresh()->getAttribute('decision')['reason']);

        Tenancy::run($this->tenant('a'), function () use ($mapped, $rejected, $napa): void {
            $this->assertSame($napa->id, CustomBrand::query()->findOrFail($mapped->id)->master_brand_id);
            $row = CustomBrand::query()->findOrFail($rejected->id);
            $this->assertSame('rejected', $row->review_status->value);
            $this->assertSame('not a registered product', $row->review_note);
            $this->assertFalse($row->promoted_to_master);
            $this->assertFalse($row->isUsable(), 'rejected brands leave autocomplete and drafts');
            $this->assertFalse($row->shouldBeSearchable());
        });
    }
}
