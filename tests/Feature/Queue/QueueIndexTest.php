<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Doctor;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Queue\Concerns\QueueFixtures;
use Tests\TestCase;

/**
 * `panel.queue.index` (GET /panel/queue) — the sidebar's "Live queue" landing. It is a dispatcher, not a third page:
 * a doctor lands on their own call-next screen, everyone else on the branch overview, which keeps its own name
 * (`panel.queue.today`) and its own URL.
 */
#[Group('realtime')]
final class QueueIndexTest extends TestCase
{
    use QueueFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_the_sidebar_entry_has_a_route_and_the_overview_kept_its_name(): void
    {
        $this->assertTrue(Route::has('panel.queue.index'));
        $this->assertTrue(Route::has('panel.queue.today'));
        $this->assertSame('/panel/queue', parse_url(route('panel.queue.index'), PHP_URL_PATH));
        $this->assertSame('/panel/queue/today', parse_url(route('panel.queue.today'), PHP_URL_PATH));
    }

    public function test_a_doctor_lands_on_their_own_screen(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $session = $this->queueSession($doctor);

        $this->get('/panel/queue')->assertRedirect('/panel/queue/doctor');
        $this->get('/panel/queue/doctor')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Queue/Doctor')->where('doctor.public_id', $doctor->public_id)->where('session_id', $session->public_id));
    }

    public function test_the_desk_and_the_admin_land_on_the_branch_overview(): void
    {
        $branch = $this->mainBranch();
        $session = $this->queueSession($this->queueDoctor());

        foreach ([Role::Receptionist, Role::HospitalAdmin] as $role) {
            $this->actingAsStaff($role, $branch);
            $this->get('/panel/queue')->assertRedirect('/panel/queue/today');
            $this->get('/panel/queue/today')
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component('Queue/Today')->where('board.branch', $branch->public_id)->has('board.sessions', 1)->where('board.sessions.0.id', $session->public_id));
        }
    }

    public function test_an_accountant_lands_on_the_overview_which_carries_codes_and_counts_only(): void
    {
        $this->queueSession($this->queueDoctor());
        $this->actingAsStaff(Role::Accountant, $this->mainBranch());

        $this->get('/panel/queue')->assertRedirect('/panel/queue/today');
        $this->get('/panel/queue/today')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Queue/Today')->where('can.call_next', false));
        $this->get('/panel/queue/doctor')->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/panel/queue')->assertRedirect();
        $this->assertStringContainsString('/login', $this->get('/panel/queue')->headers->get('Location') ?? '');
    }
}
