<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\CreateCustomBrand;
use App\Domain\Catalog\Data\CustomBrandData;
use App\Domain\Shared\Actor;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\CustomBrandPromotion;
use App\Models\Central\PlatformMessage;
use App\Models\Tenant\CustomBrand;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The completed review queue (CATALOG.md §8): filters by clinic and text, the clinic's proposal next to the
 * master brands that resemble it (each with its presentations), and every decision as a `catalog_promote` row in
 * audit_logs_central — a rejection also reaching the clinic owner by mail, in the platform's outbound log.
 */
#[Group('catalog')]
final class SuperPromotionReviewTest extends TestCase
{
    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'catalog', 'catalog_admin'];

    public function test_the_queue_filters_and_shows_the_proposal_against_similar_master_brands(): void
    {
        $paracetamol = (int) DB::connection('catalog')->table('generics')->where('slug', 'paracetamol')->value('id');
        $tab = (int) DB::connection('catalog')->table('dosage_forms')->where('code', 'tab')->value('id');

        $this->asTenant('a');
        $this->actingAsDoctor();
        $brandA = app(CreateCustomBrand::class)->handle(new CustomBrandData(brandName: 'Napa Plus', genericId: $paracetamol, manufacturer: 'Local Pharma', strength: '500 mg', dosageFormId: $tab), Actor::system());

        $this->asTenant('b');
        $this->actingAsDoctor();
        app(CreateCustomBrand::class)->handle(new CustomBrandData(brandName: 'Zzyzx', genericId: $paracetamol), Actor::system());

        $tenantA = $this->tenant('a');
        $promotion = CustomBrandPromotion::query()->where('tenant_id', $tenantA->id)->where('custom_brand_id', $brandA->id)->firstOrFail();

        $this->actingAsSuper();

        $this->getJson('/catalog/promotions/tenants')->assertOk()->assertJsonFragment(['public_id' => $tenantA->public_id, 'name' => $tenantA->name]);
        $this->getJson('/catalog/promotions?status=pending')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/catalog/promotions?status=pending&tenant='.$tenantA->public_id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.brand_name', 'Napa Plus')->assertJsonPath('data.0.tenant.public_id', $tenantA->public_id);
        $this->getJson('/catalog/promotions?status=pending&q=zzy')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.brand_name', 'Zzyzx');
        $this->getJson('/catalog/promotions?status=pending&q=paracet')->assertOk()->assertJsonCount(2, 'data');

        $detail = $this->getJson("/catalog/promotions/{$promotion->public_id}")->assertOk()
            ->assertJsonPath('proposed.brand_name', 'Napa Plus')
            ->assertJsonPath('proposed.strength', '500 mg')
            ->assertJsonPath('proposed.form', 'Tablet')
            ->assertJsonPath('catalog_generic.name', 'Paracetamol')
            ->assertJsonPath('catalog_generic.is_active', true)
            ->json();

        $similar = collect((array) $detail['similar_master_brands']);
        $napa = $similar->firstWhere('name', 'Napa');
        $this->assertNotNull($napa, 'trigram similarity offers Napa for "Napa Plus"');
        $this->assertTrue($napa['same_generic']);
        $this->assertNotEmpty($napa['strengths']);
        $this->assertContains('500 mg', array_column($napa['strengths'], 'strength_label'));
    }

    public function test_approve_and_reject_are_audited_and_a_rejection_reaches_the_clinic_owner(): void
    {
        $paracetamol = (int) DB::connection('catalog')->table('generics')->where('slug', 'paracetamol')->value('id');
        $napa = (int) DB::connection('catalog')->table('brands')->where('name', 'Napa')->value('id');

        $this->asTenant('a');
        $this->actingAsDoctor();
        $mapped = app(CreateCustomBrand::class)->handle(new CustomBrandData(brandName: 'Napa Extra', genericId: $paracetamol, strength: '500 mg'), Actor::system());
        $rejected = app(CreateCustomBrand::class)->handle(new CustomBrandData(brandName: 'Dubious', genericId: $paracetamol), Actor::system());

        $tenant = $this->tenant('a');
        $pMapped = CustomBrandPromotion::query()->where('custom_brand_id', $mapped->id)->firstOrFail();
        $pRejected = CustomBrandPromotion::query()->where('custom_brand_id', $rejected->id)->firstOrFail();

        $admin = $this->actingAsSuper();

        $this->postJson("/catalog/promotions/{$pMapped->public_id}/approve", ['mode' => 'map', 'brand_id' => $napa, 'note' => 'same product'])->assertOk()->assertJsonPath('master.brand_id', $napa);

        $approved = AuditLogCentral::query()->where('action', 'catalog_promote')->where('auditable_type', CustomBrandPromotion::class)->where('auditable_id', $pMapped->id)->first();
        $this->assertInstanceOf(AuditLogCentral::class, $approved);
        $this->assertSame($admin->id, $approved->super_admin_id);
        $this->assertSame($tenant->id, $approved->tenant_id);
        $this->assertSame(['status' => 'pending'], $approved->before);
        $this->assertSame('promoted', $approved->after['status']);
        $this->assertSame('map', $approved->after['mode']);
        $this->assertSame($napa, $approved->after['brand_id']);

        $this->postJson("/catalog/promotions/{$pRejected->public_id}/reject", ['reason' => 'not a registered product'])->assertOk()
            ->assertJsonPath('promotion.status', 'rejected')->assertJsonPath('notified', true);

        $rejection = AuditLogCentral::query()->where('action', 'catalog_promote')->where('auditable_id', $pRejected->id)->first();
        $this->assertInstanceOf(AuditLogCentral::class, $rejection);
        $this->assertSame('rejected', $rejection->after['status']);
        $this->assertSame('not a registered product', $rejection->after['reason']);

        // The reason reached the clinic: on its own custom-brand row, and by mail to the owner (platform ledger).
        Tenancy::run($tenant, function () use ($rejected): void {
            $this->assertSame('not a registered product', CustomBrand::query()->findOrFail($rejected->id)->review_note);
        });
        $mail = PlatformMessage::query()->where('kind', 'promotion_rejected')->where('tenant_id', $tenant->id)->latest('id')->first();
        $this->assertInstanceOf(PlatformMessage::class, $mail);
        $this->assertSame('sent', $mail->status);
        $this->assertSame('email', $mail->channel);
        $this->assertSame($admin->id, $mail->sent_by_super_admin_id);
        $this->assertStringContainsString('Dubious', (string) $mail->subject);
        $this->assertStringNotContainsString($tenant->owner_email, $mail->recipient, 'recipients are masked in the ledger');
    }
}
