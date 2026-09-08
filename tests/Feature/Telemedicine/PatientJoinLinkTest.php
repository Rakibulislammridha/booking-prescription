<?php

declare(strict_types=1);

namespace Tests\Feature\Telemedicine;

use App\Domain\Booking\Exceptions\AlreadyBooked;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Actions\CancelRoom;
use App\Domain\Telemedicine\Actions\EndCall;
use App\Domain\Telemedicine\Enums\SessionEndReason;
use App\Domain\Telemedicine\Services\JoinLink;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Patient;
use App\Models\Tenant\TelemedicineRoom;
use Tests\Feature\Telemedicine\Concerns\TelemedicineFixtures;
use Tests\TestCase;

/**
 * The signed, expiring link a patient receives by SMS (BRIEF §5.J/§5.K). Possession of it authenticates the
 * patient on the `patient` guard, so every way it can be forged, replayed or outlived has a test.
 */
final class PatientJoinLinkTest extends TestCase
{
    use TelemedicineFixtures;

    private function link(TelemedicineRoom $room): string
    {
        return app(JoinLink::class)->relative($room);
    }

    private function room(): TelemedicineRoom
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $session = $this->openSessionFor($doctor);
        $booking = $this->bookTelemedicine($session);

        return $this->roomFor($booking->appointment->id);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->enableTelemedicine();
    }

    public function test_a_valid_link_logs_the_patient_in_and_opens_the_waiting_room(): void
    {
        $room = $this->room();
        $this->flushSession();                                   // the patient is a stranger: no staff session

        $this->get($this->link($room))
            ->assertRedirect('/telemedicine/room/'.$room->room_name);

        $this->assertAuthenticatedAs($room->appointment->patient, 'patient');
        $this->get('/telemedicine/room/'.$room->room_name)->assertOk();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $room = $this->room();
        $url = $this->link($room);
        $this->flushSession();

        $this->travelTo(now()->addDays(3));

        $this->get($url)->assertStatus(403);
        $this->assertGuest('patient');
    }

    public function test_a_tampered_room_name_is_refused(): void
    {
        $room = $this->room();
        $other = TelemedicineRoom::factory()->create();
        $url = str_replace($room->room_name, $other->room_name, $this->link($room));
        $this->flushSession();

        $this->get($url)->assertStatus(403);
        $this->assertGuest('patient');
    }

    public function test_a_tampered_expiry_is_refused(): void
    {
        $room = $this->room();
        $url = $this->link($room);
        $tampered = (string) preg_replace('/expires=\d+/', 'expires='.now()->addYear()->getTimestamp(), $url);
        $this->flushSession();

        $this->assertNotSame($url, $tampered);
        $this->get($tampered)->assertStatus(403);
        $this->assertGuest('patient');
    }

    public function test_an_unsigned_link_is_refused(): void
    {
        $room = $this->room();
        $this->flushSession();

        $this->get('/telemedicine/j/'.$room->room_name)->assertStatus(403);
        $this->assertGuest('patient');
    }

    public function test_the_link_stops_working_once_the_visit_is_closed(): void
    {
        $room = $this->room();
        $url = $this->link($room);
        $this->startCall($room);
        app(EndCall::class)->handle($room->refresh(), SessionEndReason::Completed, Actor::system());
        $this->flushSession();

        $this->get($url)->assertStatus(410);
        $this->assertGuest('patient');
    }

    public function test_the_link_stops_working_once_the_room_is_cancelled(): void
    {
        $room = $this->room();
        $url = $this->link($room);
        app(CancelRoom::class)->handle($room);
        $this->flushSession();

        $this->get($url)->assertStatus(410);
    }

    public function test_another_patient_cannot_open_the_waiting_room(): void
    {
        $room = $this->room();
        $this->flushSession();
        $this->actingAsPatient(Patient::factory()->create(['mobile' => '+8801999999999']));

        $this->get('/telemedicine/room/'.$room->room_name)->assertStatus(403);
        $this->postJson('/telemedicine/room/'.$room->room_name.'/token')->assertStatus(403);
    }

    public function test_a_guest_is_sent_to_the_portal_login(): void
    {
        $room = $this->room();
        $this->flushSession();

        $this->get('/telemedicine/room/'.$room->room_name)->assertRedirect(route('site.portal.login'));
    }

    public function test_the_invite_sms_carries_the_join_link(): void
    {
        $room = $this->room();

        $notification = Notification::query()
            ->where('event_key', NotificationEvent::TelemedicineInvite->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($notification, 'the Notifications module sent the invite; this module built no sender');
        $this->assertSame($room->appointment->patient_id, $notification->patient_id);
        $this->assertStringContainsString('/telemedicine/j/'.$room->room_name, (string) $notification->body);
        $this->assertStringContainsString('signature=', (string) $notification->body);
        $this->assertSame('telemedicine_invite:'.$room->id.':sms', $notification->dedupe_key);
    }

    public function test_a_replayed_booking_does_not_send_a_second_invite(): void
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $session = $this->openSessionFor($doctor);
        $this->bookTelemedicine($session);
        $before = Notification::query()->where('event_key', NotificationEvent::TelemedicineInvite->value)->count();

        // Same patient, same session: Booking answers with the existing appointment rather than a second one.
        try {
            $this->bookTelemedicine($session);
        } catch (AlreadyBooked) {
            // expected
        }

        $this->assertSame($before, Notification::query()->where('event_key', NotificationEvent::TelemedicineInvite->value)->count());
    }

    public function test_the_serial_is_untouched_by_opening_the_link(): void
    {
        $room = $this->room();
        $this->flushSession();

        $this->get($this->link($room));

        $this->assertSame(SerialStatus::Booked, $room->appointment->serial->status, 'a patient arriving early does not call themselves');
    }
}
