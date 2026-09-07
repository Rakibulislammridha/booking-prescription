<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Catalog\Actions\CreateCustomBrand;
use App\Domain\Catalog\Data\CustomBrandData;
use App\Domain\Catalog\Exceptions\GenericNotUsable;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Shared\Actor;
use App\Models\Central\CustomBrandPromotion;
use App\Models\Tenant\CustomBrand;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('catalog')]
final class CustomBrandCrudTest extends TestCase
{
    private int $paracetamol;

    private int $tab;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->paracetamol = (int) DB::connection('catalog')->table('generics')->where('slug', 'paracetamol')->value('id');
        $this->tab = (int) DB::connection('catalog')->table('dosage_forms')->where('code', 'tab')->value('id');
    }

    public function test_doctor_creates_a_custom_brand_which_is_queued_for_promotion(): void
    {
        $this->asTenant('a');
        $user = $this->actingAsDoctor();

        $this->post('/panel/custom-brands', ['brand_name' => 'Paramax', 'generic_id' => $this->paracetamol, 'manufacturer' => 'Local Pharma', 'strength' => '500mg', 'dosage_form_id' => $this->tab])
            ->assertRedirect('/panel/custom-brands')->assertSessionHas('success');

        $brand = CustomBrand::query()->where('brand_name', 'Paramax')->firstOrFail();
        $this->assertSame('Paracetamol', $brand->generic_name);
        $this->assertSame('500 mg', $brand->strength);                                                   // normalised by the parser
        $this->assertSame('Tablet', $brand->form);
        $this->assertSame('Oral', $brand->route);                                                        // form default route snapshot
        $this->assertSame('pending', $brand->review_status->value);
        $this->assertSame($user->id, $brand->created_by_user_id);
        $this->assertTrue($brand->isUsable());
        $this->assertAudited(AuditAction::Create, $brand);

        $promotion = CustomBrandPromotion::query()->where('tenant_id', 9001)->where('custom_brand_id', $brand->id)->firstOrFail();
        $this->assertSame('pending', $promotion->status);
        $this->assertSame('Paramax', $promotion->brand_name);
        $this->assertSame('500 mg', $promotion->getAttribute('snapshot')['strength']);
        $this->assertSame($user->public_id, $promotion->getAttribute('snapshot')['created_by_user_public_id']);

        $this->get('/panel/custom-brands')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Catalog/CustomBrands')
            ->has('brands.data', 1)->where('brands.data.0.brand_name', 'Paramax')->where('can.create', true)->has('forms', 26)->has('routes', 20));
    }

    public function test_brand_without_a_generic_is_rejected_with_422(): void
    {
        $this->asTenant('a');
        $this->actingAsDoctor();

        $this->postJson('/panel/custom-brands', ['brand_name' => 'Orphanol'])->assertStatus(422)->assertJsonValidationErrors(['generic_id']);
        $message = $this->postJson('/panel/custom-brands', ['brand_name' => 'Orphanol', 'generic_id' => 999999])->assertStatus(422)->assertJsonValidationErrors(['generic_id'])->json('errors.generic_id.0');
        $this->assertContains($message, [                                                                     // the tenant locale (bn) applies over HTTP
            'The selected generic id does not exist in the drug catalog.',
            __('catalog.validation.not_in_catalog', ['attribute' => 'generic id'], 'bn'),
        ]);
        $this->postJson('/panel/custom-brands', ['brand_name' => 'Orphanol', 'generic_id' => $this->paracetamol, 'dosage_form_id' => 4242])->assertStatus(422)->assertJsonValidationErrors(['dosage_form_id']);
        $this->assertSame(0, CustomBrand::query()->count());

        // The action itself refuses an unusable generic with a dotted domain code (I4 at the last line of defence).
        try {
            app(CreateCustomBrand::class)->handle(new CustomBrandData(brandName: 'Orphanol', genericId: 999999), Actor::system());
            $this->fail('expected GenericNotUsable');
        } catch (GenericNotUsable $e) {
            $this->assertSame('catalog.generic_not_usable', $e->code());
            $this->assertSame(422, $e->status());
        }
    }

    public function test_update_delete_and_policy(): void
    {
        $this->asTenant('a');
        $this->actingAsStaff(Role::Receptionist);
        $this->postJson('/panel/custom-brands', ['brand_name' => 'Nope', 'generic_id' => $this->paracetamol])->assertForbidden();

        $admin = $this->actingAsStaff(Role::HospitalAdmin);
        $brand = CustomBrand::factory()->create(['brand_name' => 'Paramax', 'created_by_user_id' => $admin->id]);

        $this->put("/panel/custom-brands/{$brand->id}", ['brand_name' => 'Paramax Forte', 'generic_id' => $this->paracetamol, 'strength' => '650 mg'])->assertRedirect('/panel/custom-brands');
        $this->assertSame('Paramax Forte', $brand->refresh()->brand_name);
        $this->assertSame('650 mg', $brand->strength);

        $this->delete("/panel/custom-brands/{$brand->id}")->assertRedirect('/panel/custom-brands');
        $this->assertSoftDeleted('custom_brands', ['id' => $brand->id]);
        $this->assertAudited(AuditAction::Delete, $brand);

        $this->actingAsStaff(Role::Doctor);
        $other = CustomBrand::factory()->create(['brand_name' => 'Docbrand']);
        $this->delete("/panel/custom-brands/{$other->id}")->assertForbidden();
    }

    public function test_custom_brands_are_tenant_isolated(): void
    {
        $this->assertTenantIsolated('custom_brands', function (): void {
            $this->actingAsDoctor();
            app(CreateCustomBrand::class)->handle(new CustomBrandData(brandName: 'Isolated', genericId: $this->paracetamol), Actor::system());
        });
    }
}
