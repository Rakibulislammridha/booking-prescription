<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Patients\Contracts\PatientTimelineSource;
use App\Domain\Patients\Data\TimelineCursor;
use App\Domain\Patients\Data\TimelineEntry;
use App\Domain\Patients\Queries\PatientTimelineQuery;
use App\Domain\Patients\Queries\VitalsTrendQuery;
use App\Domain\Patients\Services\TimelineSourceRegistry;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientAllergy;
use App\Models\Tenant\PatientCondition;
use App\Models\Tenant\PatientConsent;
use App\Models\Tenant\PatientDocument;
use App\Models\Tenant\PatientMedication;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class TimelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_aggregates_every_owned_source_newest_first_and_paginates_with_a_keyset_cursor(): void
    {
        $patient = Patient::factory()->create();
        $other = Patient::factory()->create();
        $t0 = CarbonImmutable::parse('2026-09-01 08:00:00', 'UTC');

        $allergy = PatientAllergy::factory()->for($patient)->create(['created_at' => $t0, 'allergen_name' => 'Shrimp']);
        $condition = PatientCondition::factory()->for($patient)->create(['created_at' => $t0->addHour(), 'condition_name' => 'HTN']);
        $medication = PatientMedication::factory()->for($patient)->create(['created_at' => $t0->addHours(2), 'generic_name' => 'Amlodipine', 'brand_name' => 'Amdocal']);
        $document = PatientDocument::factory()->for($patient)->create(['created_at' => $t0->addHours(3), 'title' => 'CBC']);
        $consent = PatientConsent::factory()->for($patient)->create(['occurred_at' => $t0->addHours(4), 'type' => 'sms']);
        $tie = PatientDocument::factory()->for($patient)->create(['created_at' => $t0->addHours(4), 'title' => 'Tie']);   // same instant as the consent
        PatientDocument::factory()->for($other)->create(['created_at' => $t0->addDay()]);

        $query = app(PatientTimelineQuery::class);
        foreach (['document', 'allergy', 'condition', 'medication', 'consent'] as $owned) {   // other modules register more (visit, vital, prescription)
            $this->assertContains($owned, $query->kinds());
        }

        $all = $query->fetch($patient, null, 25);
        $this->assertNull($all->nextCursor);
        $this->assertSame(
            [['document', $tie->id], ['consent', $consent->id], ['document', $document->id], ['medication', $medication->id], ['condition', $condition->id], ['allergy', $allergy->id]],
            array_map(fn (TimelineEntry $e) => [$e->kind, $e->id], $all->entries),
        );
        $this->assertSame('Amdocal (Amlodipine)', $all->entries[3]->title);

        $page1 = $query->fetch($patient, null, 2);
        $this->assertNotNull($page1->nextCursor);
        $this->assertSame([$tie->id, $consent->id], array_map(fn (TimelineEntry $e) => $e->id, $page1->entries));

        $page2 = $query->fetch($patient, $page1->nextCursor, 2);
        $this->assertSame([['document', $document->id], ['medication', $medication->id]], array_map(fn (TimelineEntry $e) => [$e->kind, $e->id], $page2->entries));

        $page3 = $query->fetch($patient, $page2->nextCursor, 2);
        $this->assertSame([$condition->id, $allergy->id], array_map(fn (TimelineEntry $e) => $e->id, $page3->entries));
        $this->assertNull($page3->nextCursor);

        $only = $query->fetch($patient, null, 25, ['document', 'consent']);
        $this->assertSame(['document', 'consent', 'document'], array_map(fn (TimelineEntry $e) => $e->kind, $only->entries));
    }

    public function test_other_modules_register_sources_through_the_registry(): void
    {
        $patient = Patient::factory()->create();
        $registry = app(TimelineSourceRegistry::class);
        $registry->register(new class implements PatientTimelineSource
        {
            public function kind(): string
            {
                return 'visit';
            }

            public function entries(Patient $patient, ?TimelineCursor $cursor, int $limit): array
            {
                return [new TimelineEntry('visit', 1, CarbonImmutable::parse('2030-01-01', 'UTC'), 'Future visit', ref: '01J0000000000000000000VISIT')];
            }
        });

        $page = app(PatientTimelineQuery::class)->fetch($patient, null, 5);

        $this->assertContains('visit', app(PatientTimelineQuery::class)->kinds());
        $this->assertSame('visit', $page->entries[0]->kind);
        $this->assertSame('Future visit', $page->toArray()['data'][0]['title']);
    }

    public function test_vitals_trend_is_available_with_the_prescription_module_and_empty_without_readings(): void
    {
        $patient = Patient::factory()->create();
        $query = app(VitalsTrendQuery::class);

        $this->assertTrue($query->available());          // the Prescription module ships `vitals`; it degrades to false only without it
        $this->assertSame([], $query->for($patient));    // no readings recorded for this patient yet
    }

    public function test_the_panel_timeline_and_vitals_endpoints_are_json_and_audited(): void
    {
        $this->actingAsDoctor();
        $patient = Patient::factory()->create();
        PatientAllergy::factory()->for($patient)->count(3)->create();

        $first = $this->getJson('/panel/patients/'.$patient->public_id.'/timeline?limit=2')->assertOk()->assertJsonCount(2, 'data');
        $cursor = $first->json('meta.next_cursor');
        $this->assertIsString($cursor);
        $this->getJson('/panel/patients/'.$patient->public_id.'/timeline?limit=2&cursor='.$cursor)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);
        $this->getJson('/panel/patients/'.$patient->public_id.'/timeline?kinds=document')->assertOk()->assertJsonCount(0, 'data');
        $this->assertAudited(AuditAction::View, $patient, ['section' => 'timeline']);

        $this->getJson('/panel/patients/'.$patient->public_id.'/vitals-trend')->assertOk()->assertJson(['data' => [], 'available' => true]);
        $this->assertAudited(AuditAction::View, $patient, ['section' => 'vitals_trend']);
    }
}
