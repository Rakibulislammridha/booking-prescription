<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Queue\TenantChannel;
use App\Domain\Serials\Enums\SerialPool;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Queue\Concerns\QueueFixtures;
use Tests\TestCase;

/** The Inertia pages and the JSON reads of routes/{site,panel,api}/queue.php (REALTIME.md §5.1, §8, §9, §11). */
#[Group('realtime')]
final class QueuePagesTest extends TestCase
{
    use QueueFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    private function tenantId(): string
    {
        return (string) Tenancy::current()?->public_id;
    }

    public function test_the_public_queue_page_renders_without_login_and_embeds_the_state_for_the_first_paint(): void
    {
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $this->issueMany($session, 2);

        $this->get('/q/'.$doctor->slug.'/today?s=ser_x')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Queue/Today')
                ->where('doctor.slug', $doctor->slug)
                ->where('session_id', $session->public_id)
                ->where('channel', TenantChannel::queueName($this->tenantId(), $session->public_id))
                ->where('serial', 'ser_x')
                ->where('notify_ahead', 3)
                ->has('state.serials', 2)
                ->where('state.version', $session->fresh()?->version)
                ->has('sessions', 1)
            );
    }

    public function test_the_vanity_host_renders_the_same_page(): void
    {
        $doctor = $this->queueDoctor();
        $this->queueSession($doctor);

        $this->withServerVariables(['HTTP_HOST' => 'queue.test-a.bp.test'])
            ->get('http://queue.test-a.bp.test/'.$doctor->slug.'/today')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Queue/Today')->where('doctor.slug', $doctor->slug));
    }

    public function test_the_page_renders_when_the_doctor_has_no_session_today(): void
    {
        $doctor = $this->queueDoctor();

        $this->get('/q/'.$doctor->slug.'/today')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Queue/Today')->where('state', null)->where('session_id', null)->has('sessions', 0));
    }

    public function test_the_sessions_endpoint_lists_todays_instances_and_names_the_current_one(): void
    {
        $doctor = $this->queueDoctor();
        $morning = $this->queueSession($doctor, 'A', start: '09:00', end: '13:00');
        $evening = $this->queueSession($doctor, 'B', start: '17:00', end: '21:00');

        $this->get('/queue/'.$doctor->slug.'/sessions')
            ->assertOk()
            ->assertJsonPath('current', $morning->public_id)
            ->assertJsonPath('doctor.slug', $doctor->slug)
            ->assertJsonCount(2, 'sessions')
            ->assertJsonPath('sessions.1.public_id', $evening->public_id);
    }

    public function test_a_local_slip_id_resolves_once_the_desk_has_synced(): void
    {
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $clientEventId = (string) Str::ulid();

        $this->get('/q/resolve/local:'.$clientEventId)->assertOk()->assertJsonPath('resolved', false);

        $serial = $this->issue($session);
        Serial::query()->whereKey($serial->id)->update(['client_event_id' => $clientEventId]);

        $this->get('/q/resolve/local:'.$clientEventId)
            ->assertOk()
            ->assertJsonPath('resolved', true)
            ->assertJsonPath('serial.public_id', $serial->public_id)
            ->assertJsonPath('session.public_id', $session->public_id)
            ->assertJsonPath('doctor.slug', $doctor->slug);
    }

    public function test_the_display_page_needs_a_display_device_a_signed_url_or_staff(): void
    {
        $branch = $this->mainBranch();
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $this->callAfterStart($session);

        $this->get('/display/'.$branch->slug)->assertForbidden();

        $device = ReceptionDevice::factory()->display()->create(['branch_id' => $branch->id]);
        $token = $device->createToken('tv', ReceptionDevice::ABILITIES)->plainTextToken;

        $this->get('/display/'.$branch->slug.'?token='.$token.'&kiosk=1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Display/Board')
                ->where('channel', TenantChannel::displayName($this->tenantId(), $branch->public_id))
                ->where('kiosk', true)
                ->where('voice', 'both')
                ->where('device_token', $token)
                ->has('tiles', 1)
                ->has('states.'.$session->public_id)
            );

        // a reception-kind device token is not a display token
        $desk = ReceptionDevice::factory()->create(['branch_id' => $branch->id]);
        $this->get('/display/'.$branch->slug.'?token='.$desk->createToken('desk', ReceptionDevice::ABILITIES)->plainTextToken)->assertForbidden();

        // a signed URL works for a TV without a device row (signed against the tenant host, like the kiosk QR)
        URL::forceRootUrl('http://test-a.bp.test');
        $signed = URL::signedRoute('site.queue.display', ['branchSlug' => $branch->slug]);
        URL::forceRootUrl(null);

        $this->get($signed)->assertOk();
        $this->get($signed.'&tampered=1')->assertForbidden();

        // …and so does a logged-in staff user previewing it
        $this->actingAsStaff(Role::Receptionist, $branch);
        $this->get('/display/'.$branch->slug)->assertOk();
    }

    public function test_the_display_page_never_ships_a_patient_name(): void
    {
        $branch = $this->mainBranch();
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $patient = Patient::factory()->create(['name' => 'Rahima Begum']);
        $this->checkIn($this->issue($session, $patient->id, SerialPool::Counter));

        $this->actingAsStaff(Role::Receptionist, $branch);
        $response = $this->get('/display/'.$branch->slug);

        $response->assertOk();
        $this->assertStringNotContainsString('Rahima', $response->getContent() ?: '');
    }

    public function test_the_doctor_screen_shows_the_doctors_own_session_with_the_patient_cards(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $session = $this->queueSession($doctor);
        $patient = Patient::factory()->create(['name' => 'Rahima Begum']);
        $serial = $this->issue($session, $patient->id);

        $this->get('/panel/queue/doctor')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Queue/Doctor')
                ->where('doctor.public_id', $doctor->public_id)
                ->where('doctor_channel', TenantChannel::doctorName($this->tenantId(), $doctor->public_id))
                ->where('session_id', $session->public_id)
                ->where('can.call_next', true)
                ->where('can.prescribe', true)   // the screen's Prescribe → visits.start → writer (BRIEF §5.G)
                ->where('patients.'.$serial->public_id.'.name', 'Rahima Begum')
                ->has('state.serials', 1)
            );
    }

    /** An operator running a doctor's screen may call next but is not a prescriber: no Prescribe for them. */
    public function test_an_operator_on_a_doctors_screen_gets_call_next_but_not_prescribe(): void
    {
        $other = $this->queueDoctor('dr-other');
        $this->queueSession($other);

        $this->actingAsStaff(Role::Receptionist, $this->mainBranch());
        $this->get('/panel/queue/doctor?doctor='.$other->slug)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Queue/Doctor')
                ->where('can.call_next', true)
                ->where('can.prescribe', false)
            );
    }

    public function test_a_doctor_cannot_open_another_doctors_screen_but_an_operator_can(): void
    {
        $other = $this->queueDoctor('dr-other');
        $this->queueSession($other);

        $this->actingAsDoctor();
        $this->get('/panel/queue/doctor?doctor='.$other->slug)->assertForbidden();

        $this->actingAsStaff(Role::Receptionist, $this->mainBranch());
        $this->get('/panel/queue/doctor?doctor='.$other->slug)->assertOk();

        $this->actingAsStaff(Role::Accountant, $this->mainBranch());
        $this->get('/panel/queue/doctor?doctor='.$other->slug)->assertForbidden();
    }

    public function test_the_panel_overview_lists_todays_sessions_of_the_active_branch(): void
    {
        $branch = $this->mainBranch();
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $this->issueMany($session, 2);
        $this->actingAsStaff(Role::Receptionist, $branch);

        $this->get('/panel/queue/today')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Queue/Today')
                ->where('channel', TenantChannel::receptionName($this->tenantId(), $branch->public_id))
                ->where('board.branch', $branch->public_id)
                ->has('board.sessions', 1)
                ->where('board.sessions.0.id', $session->public_id)
                ->where('board.sessions.0.counts.booked', 2)
                ->where('doctors.'.$doctor->public_id.'.slug', $doctor->slug)
            );

        $this->get('/panel/queue/today-data')
            ->assertOk()
            ->assertJsonPath('board.branch', $branch->public_id)
            ->assertJsonPath('board.sessions.0.remaining.online', 10);
    }

    public function test_the_api_session_state_endpoint_mirrors_the_public_one_for_authenticated_clients(): void
    {
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $this->issue($session);
        $session->refresh();

        $this->getJson('/api/queue/sessions/'.$session->public_id.'/state')->assertUnauthorized();

        $this->actingAsStaff(Role::Receptionist, $this->mainBranch());
        $response = $this->getJson('/api/queue/sessions/'.$session->public_id.'/state');
        $response->assertOk()->assertHeader('ETag', '"'.$session->version.'"');
        $this->assertSame($session->version, $response->json('version'));

        $this->getJson('/api/queue/sessions/'.$session->public_id.'/state', ['If-None-Match' => '"'.$session->version.'"'])->assertStatus(304);
    }

    public function test_the_api_display_tiles_endpoint_is_limited_to_display_devices_and_staff(): void
    {
        $branch = $this->mainBranch();
        $doctor = $this->queueDoctor();
        $this->queueSession($doctor);

        $display = ReceptionDevice::factory()->display()->create(['branch_id' => $branch->id]);
        $token = $display->createToken('tv', ReceptionDevice::ABILITIES)->plainTextToken;

        $this->getJson('/api/queue/display/'.$branch->public_id, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('branch.public_id', $branch->public_id)
            ->assertJsonCount(1, 'tiles');

        $this->app['auth']->forgetGuards();
        $desk = ReceptionDevice::factory()->create(['branch_id' => $branch->id]);
        $this->getJson('/api/queue/display/'.$branch->public_id, ['Authorization' => 'Bearer '.$desk->createToken('desk', ReceptionDevice::ABILITIES)->plainTextToken])
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/queue/display/'.$branch->public_id)->assertUnauthorized();
    }

    public function test_the_display_page_404s_for_an_unknown_branch(): void
    {
        $this->actingAsStaff(Role::Receptionist, $this->mainBranch());
        $this->get('/display/no-such-branch')->assertNotFound();
        $this->assertNotNull(Branch::query()->where('is_main', true)->first());
    }

    private function callAfterStart(SessionInstance $session): void
    {
        $serial = $this->issue($session);
        $this->checkIn($serial);
        $this->callNext($session->fresh());
    }
}
