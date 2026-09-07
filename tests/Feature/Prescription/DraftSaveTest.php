<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Audit\Enums\AuditAction;
use App\Models\Tenant\AdviceSnippet;
use App\Models\Tenant\InvestigationCatalogItem;
use App\Models\Tenant\PrescriptionItem;
use App\Models\Tenant\Vital;
use Tests\TestCase;

/** PRESCRIPTION.md §9.1 DraftSaveTest: upsert by key, visit keys, overrides per item, 422 on parse error, 409 stale, alerts, audit. */
final class DraftSaveTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_save_upserts_items_by_id_writes_visit_keys_and_snapshots_catalog_text(): void
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit();
        $draft = $this->draftFor($visit, $doctor);
        $snippet = AdviceSnippet::factory()->create(['text' => 'Drink plenty of water', 'text_bn' => 'প্রচুর পানি পান করুন']);
        $test = InvestigationCatalogItem::factory()->create(['name' => 'CBC', 'price_paisa' => 40000]);
        $napa = $this->presentation('paracetamol', 'tab', '500 mg');

        $body = [
            'language' => 'both',
            'visit' => [
                'chief_complaints' => [['text' => 'Fever', 'text_bn' => null, 'duration' => '3d', 'sort' => 0]],
                'examination_findings' => 'Throat congested',
                'diagnoses' => [['icd10_code' => 'J06.9', 'title' => 'Acute upper respiratory infection', 'kind' => 'provisional', 'sort' => 0]],
                'follow_up_on' => null, 'follow_up_note' => null,
            ],
            'follow_up_days' => 7,
            'items' => [
                ['key' => 'i1', 'sort_order' => 0, 'drug' => $napa, 'shorthand' => '1+0+1 5d af'],
                ['key' => 'i2', 'sort_order' => 1, 'drug' => $this->presentation('cetirizine', 'tab'), 'shorthand' => '0+0+1 5d'],
            ],
            'investigations' => [['key' => 'x1', 'investigation_catalog_id' => $test->id, 'is_urgent' => false]],
            'advice' => [['key' => 'a1', 'advice_snippet_id' => $snippet->id]],
            'referrals' => [['key' => 'r1', 'type' => 'doctor', 'referred_to_name' => 'Dr. Cardio', 'referred_to_specialty' => 'Cardiology', 'note' => 'Chest pain']],
        ];

        $response = $this->patchJson('/panel/prescriptions/'.$draft->public_id.'/draft', $body)->assertOk();
        $response->assertJsonPath('prescription.status', 'draft')->assertJsonPath('prescription.items.0.key', 'i1')->assertJsonPath('prescription.items.0.parsed.normalized', '1+0+1 5d af')
            ->assertJsonPath('prescription.items.0.snapshot.generic_name', 'Paracetamol')->assertJsonPath('prescription.items.0.snapshot.strength', '500 mg')
            ->assertJsonPath('prescription.items.0.quantity', 10)->assertJsonPath('prescription.items.0.display.bn.duration', '৫ দিন')
            ->assertJsonPath('prescription.investigations.0.name', 'CBC')->assertJsonPath('prescription.investigations.0.price_paisa', 40000)
            ->assertJsonPath('prescription.advice.0.text_bn', 'প্রচুর পানি পান করুন')->assertJsonPath('prescription.referrals.0.referred_to_name', 'Dr. Cardio')
            ->assertJsonStructure(['alerts', 'issue_blocked_by', 'prescription' => ['updated_at']]);

        $visit->refresh();
        $this->assertSame('Throat congested', $visit->examination_findings);
        $this->assertSame('J06.9', $visit->diagnoses[0]['icd10_code']);
        $this->assertSame(now()->addDays(7)->toDateString(), $visit->follow_up_on?->toDateString());
        $this->assertAudited(AuditAction::Update, $draft, ['event' => 'draft_saved']);
        $this->assertAudited(AuditAction::Update, $visit, ['event' => 'draft_saved']);

        // second save: keep i1 by id with a new dose, drop i2, add i3; order flips
        $items = $response->json('prescription.items');
        $second = [
            'items' => [
                ['key' => 'n3', 'sort_order' => 0, 'drug' => $this->presentation('omeprazole', 'cap'), 'shorthand' => '1 od 7d bf'],
                ['key' => 'i1', 'id' => $items[0]['id'], 'sort_order' => 1, 'drug' => $napa, 'shorthand' => '1+1+1 3d'],
            ],
            'investigations' => [], 'advice' => [], 'referrals' => [],
            'expected_updated_at' => $response->json('prescription.updated_at'),
        ];
        $r2 = $this->patchJson('/panel/prescriptions/'.$draft->public_id.'/draft', $second)->assertOk();
        $this->assertSame(2, PrescriptionItem::query()->where('prescription_id', $draft->id)->count());
        $this->assertSame($items[0]['id'], $r2->json('prescription.items.1.id'));
        $this->assertSame('n3', $r2->json('prescription.items.0.key'));
        $this->assertSame('1+1+1', PrescriptionItem::query()->findOrFail($items[0]['id'])->dose_schedule);
        $this->assertSame(0, $draft->fresh()->investigations()->count());
    }

    public function test_server_parse_error_is_422_with_the_item_key_and_issues(): void
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit();
        $draft = $this->draftFor($visit, $doctor);

        $response = $this->patchJson('/panel/prescriptions/'.$draft->public_id.'/draft', ['items' => [['key' => 'i1', 'drug' => $this->presentation('paracetamol'), 'shorthand' => '1+0+1 10d aff']], 'investigations' => [], 'advice' => [], 'referrals' => []])
            ->assertStatus(422)->assertJsonPath('code', 'prescriptions.parse_error');
        $issues = $response->json('errors')['items.i1'];
        $this->assertSame('unknown_token', $issues[0]['code']);
        $this->assertSame('af', $issues[0]['suggestion']);

        $this->assertSame(0, $draft->items()->count());
    }

    public function test_stale_expected_updated_at_is_409_with_the_server_draft(): void
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit();
        $draft = $this->draftFor($visit, $doctor);

        $this->patchJson('/panel/prescriptions/'.$draft->public_id.'/draft', ['items' => [], 'investigations' => [], 'advice' => [], 'referrals' => [], 'expected_updated_at' => now()->subHour()->toIso8601String()])
            ->assertStatus(409)->assertJsonPath('code', 'prescriptions.draft_conflict')->assertJsonPath('prescription.id', $draft->public_id);
    }

    public function test_overrides_are_stored_per_item_with_reason_and_audited_and_dropped_when_the_alert_goes(): void
    {
        [$user, $doctor, $visit] = $this->doctorWithOpenVisit();
        $draft = $this->draftFor($visit, $doctor);
        $warfarin = $this->presentation('warfarin');
        $aspirin = $this->presentation('aspirin');
        $items = [['key' => 'w', 'drug' => $warfarin, 'shorthand' => '1 od 30d'], ['key' => 'a', 'drug' => $aspirin, 'shorthand' => '1 od 30d']];

        $first = $this->patchJson('/panel/prescriptions/'.$draft->public_id.'/draft', ['items' => $items, 'investigations' => [], 'advice' => [], 'referrals' => []])->assertOk();
        $alert = collect((array) $first->json('alerts'))->firstWhere('key', 'interaction');
        $this->assertNotNull($alert, json_encode($first->json('alerts')));
        $this->assertSame('warning', $alert['severity']);
        $fingerprint = $alert['fingerprint'];
        $this->assertSame('interaction:major:'.min($warfarin['generic_id'], $aspirin['generic_id']).':'.max($warfarin['generic_id'], $aspirin['generic_id']), $fingerprint);

        $items[0]['safety_overrides'] = [['fingerprint' => $fingerprint, 'reason' => 'Short course, monitoring INR']];
        $items[1]['safety_overrides'] = [['fingerprint' => $fingerprint, 'reason' => 'Short course, monitoring INR']];
        $second = $this->patchJson('/panel/prescriptions/'.$draft->public_id.'/draft', ['items' => $items, 'investigations' => [], 'advice' => [], 'referrals' => []])->assertOk();

        $overridden = collect((array) $second->json('alerts'))->firstWhere('fingerprint', $fingerprint);
        $this->assertSame('Short course, monitoring INR', $overridden['overridden']['reason']);
        $this->assertSame($user->id, $overridden['overridden']['by']);
        $stored = PrescriptionItem::query()->where('prescription_id', $draft->id)->orderBy('sort_order')->firstOrFail()->safety_overrides;
        $this->assertSame($fingerprint, $stored[0]['fingerprint']);
        $this->assertSame('interaction', $stored[0]['kind']);
        $this->assertSame('warning', $stored[0]['severity']);
        $this->assertAudited(AuditAction::Update, PrescriptionItem::query()->where('prescription_id', $draft->id)->orderBy('sort_order')->firstOrFail(), ['event' => 'safety_override']);

        // replace aspirin with paracetamol: the fingerprint changes → override dropped
        $items[1] = ['key' => 'a', 'drug' => $this->presentation('paracetamol'), 'shorthand' => '1 od 30d'];
        $third = $this->patchJson('/panel/prescriptions/'.$draft->public_id.'/draft', ['items' => $items, 'investigations' => [], 'advice' => [], 'referrals' => []])->assertOk();
        $this->assertNull(collect((array) $third->json('alerts'))->firstWhere('fingerprint', $fingerprint));
        $this->assertSame([], PrescriptionItem::query()->where('prescription_id', $draft->id)->orderBy('sort_order')->firstOrFail()->safety_overrides);
    }

    public function test_vitals_reviewed_flag_marks_the_latest_vitals(): void
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit();
        $draft = $this->draftFor($visit, $doctor);
        $vital = Vital::factory()->for($visit)->create();

        $this->patchJson('/panel/prescriptions/'.$draft->public_id.'/draft', ['items' => [], 'investigations' => [], 'advice' => [], 'referrals' => [], 'vitals_reviewed' => true])->assertOk();
        $this->assertNotNull($vital->fresh()->reviewed_by_doctor_at);
        $this->assertAudited(AuditAction::Update, $vital, ['event' => 'vitals_reviewed']);
    }

    public function test_another_doctor_cannot_save_the_draft(): void
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit();
        $draft = $this->draftFor($visit, $doctor);
        $this->actingAsDoctor();
        $this->patchJson('/panel/prescriptions/'.$draft->public_id.'/draft', ['items' => []])->assertForbidden();
    }
}
