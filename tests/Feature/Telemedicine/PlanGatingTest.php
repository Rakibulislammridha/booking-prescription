<?php

declare(strict_types=1);

namespace Tests\Feature\Telemedicine;

use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\Telemedicine\Services\JoinLink;
use App\Models\Tenant\TelemedicineRoom;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;
use Tests\Feature\Telemedicine\Concerns\TelemedicineFixtures;
use Tests\TestCase;

/**
 * BRIEF §5.M: telemedicine is an add-on tier. A clinic without it must be told to upgrade, never shown a 404 —
 * and the panel's answer (the subscription page) must not be given to a patient holding an SMS link.
 */
final class PlanGatingTest extends TestCase
{
    use TelemedicineFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_the_pennant_feature_follows_the_plan_add_on(): void
    {
        $this->enableTelemedicine(false);
        $this->assertFalse(Feature::for($this->tenant('a'))->active('telemedicine'));

        $this->enableTelemedicine(true);
        $this->assertTrue(Feature::for($this->tenant('a'))->active('telemedicine'));
    }

    public function test_the_panel_board_is_reachable_with_the_add_on(): void
    {
        $this->enableTelemedicine();
        $this->actingAsTelemedicineDoctor();

        $this->get('/panel/telemedicine')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Telemedicine/Index')->has('rooms'));
    }

    public function test_without_the_add_on_the_panel_offers_an_upgrade_rather_than_a_404(): void
    {
        $this->enableTelemedicine(false);
        $this->actingAsTelemedicineDoctor();

        $response = $this->get('/panel/telemedicine');

        $response->assertRedirect(route('panel.saas.subscription.index'));
        $response->assertSessionHas('flash.error');
        $this->assertStringNotContainsString('404', (string) $response->getStatusCode());
    }

    public function test_without_the_add_on_the_panel_xhr_endpoints_answer_with_the_domain_code(): void
    {
        $this->enableTelemedicine(false);
        $this->actingAsTelemedicineDoctor();
        $room = TelemedicineRoom::factory()->create();

        $this->getJson('/panel/telemedicine/'.$room->room_name.'/state')
            ->assertStatus(402)
            ->assertJsonPath('code', 'saas.feature.telemedicine');
    }

    public function test_without_the_add_on_the_public_site_explains_itself_to_the_patient(): void
    {
        $this->enableTelemedicine(false);

        $response = $this->get('/telemedicine');

        $response->assertStatus(402);
        $response->assertInertia(fn (AssertableInertia $p) => $p->component('Telemedicine/Unavailable')->has('message'));
    }

    public function test_without_the_add_on_a_signed_join_link_is_refused_with_the_same_page(): void
    {
        $this->enableTelemedicine();
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $booking = $this->bookTelemedicine($this->openSessionFor($doctor));
        $room = $this->roomFor($booking->appointment->id);
        $url = app(JoinLink::class)->relative($room);

        $this->enableTelemedicine(false);
        $this->flushSession();

        $this->get($url)->assertStatus(402)->assertInertia(fn (AssertableInertia $p) => $p->component('Telemedicine/Unavailable'));
    }

    public function test_the_booking_channel_creates_no_room_when_the_add_on_lapsed(): void
    {
        $this->enableTelemedicine();
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $session = $this->openSessionFor($doctor);

        $this->enableTelemedicine(false);
        $booking = $this->bookTelemedicine($session);

        $this->assertSame(0, TelemedicineRoom::query()->where('appointment_id', $booking->appointment->id)->count());
        $this->assertTrue($booking->appointment->is_telemedicine, 'the appointment still exists — only the room does not');
    }

    public function test_the_panel_nav_entry_is_driven_by_the_shared_feature_map(): void
    {
        $this->enableTelemedicine(false);
        $this->actingAsStaff(Role::HospitalAdmin);

        $this->get('/panel')->assertInertia(fn (AssertableInertia $p) => $p->where('features.telemedicine', false));

        $this->enableTelemedicine(true);
        $this->get('/panel')->assertInertia(fn (AssertableInertia $p) => $p->where('features.telemedicine', true));
    }

    public function test_the_add_on_is_a_toggle_not_a_numeric_cap(): void
    {
        $this->assertTrue(PlanFeatureKey::Telemedicine->isToggle());
        $this->assertSame('telemedicine', PlanFeatureKey::Telemedicine->featureName());
    }
}
