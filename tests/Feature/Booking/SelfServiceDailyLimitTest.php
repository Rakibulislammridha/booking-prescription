<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Data\BookingRequest;
use App\Domain\Booking\Data\BookingResult;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Exceptions\SelfServiceLimitReached;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

/**
 * `booking.self_service_daily_limit` (SCHEMA Appendix B) — the guard that stands in for the OTP now that
 * `kiosk.otp_required` is off by default: one mobile number may make N self-service bookings per clinic-local day,
 * staff channels are never counted or limited, and the counter resets at the clinic's midnight, not UTC's.
 */
final class SelfServiceDailyLimitTest extends TestCase
{
    use SerialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    private function selfService(SessionInstance $session, string $mobile = '01712345678', string $name = 'Rahima', BookingChannel $channel = BookingChannel::Online): BookingResult
    {
        return app(BookAppointment::class)->handle(
            new BookingRequest(channel: $channel, mobile: $mobile, name: $name, sessionPublicId: $session->public_id),
            new Actor(ip: '127.0.0.1', source: 'web'),
        );
    }

    private function staff(SessionInstance $session, string $mobile = '01712345678', string $name = 'Rahima', BookingChannel $channel = BookingChannel::Counter): BookingResult
    {
        return app(BookAppointment::class)->handle(
            new BookingRequest(channel: $channel, mobile: $mobile, name: $name, sessionPublicId: $session->public_id),
            $this->staffActor(),
        );
    }

    public function test_the_fourth_self_service_booking_from_one_mobile_in_a_day_is_refused(): void
    {
        $this->assertSame(3, app(Settings::class)->get('booking.self_service_daily_limit'));
        $sessions = [$this->openSession(), $this->openSession(), $this->openSession(), $this->openSession(), $this->openSession()];

        // Online and kiosk count together: it is the mobile number that is capped, not the channel.
        $this->selfService($sessions[0]);
        $this->selfService($sessions[1], channel: BookingChannel::Kiosk);
        $this->selfService($sessions[2]);

        try {
            $this->selfService($sessions[3]);
            $this->fail('the fourth self-service booking of the day should have been refused');
        } catch (SelfServiceLimitReached $e) {
            $this->assertSame(422, $e->status());
            $this->assertSame('booking.self_service_limit_reached', $e->code());
            $this->assertSame(3, $e->limit);
            $this->assertSame(__('booking.errors.self_service_daily_limit', ['limit' => '3']), $e->getMessage());
        }

        // A household member on the same number is the same number.
        $this->assertThrows(fn () => $this->selfService($sessions[3], name: 'Karim (son)'), SelfServiceLimitReached::class);
        $this->assertSame(3, Appointment::query()->count());

        // The desk is never limited — the receptionist books the same patient a fourth and a fifth time by phone.
        $this->staff($sessions[3]);
        $this->staff($sessions[4], channel: BookingChannel::Phone);
        // …and another number is another counter.
        $this->selfService($sessions[3], mobile: '01787654321', name: 'Someone Else');
        $this->assertSame(6, Appointment::query()->count());
    }

    public function test_staff_bookings_do_not_count_toward_the_cap(): void
    {
        $sessions = [$this->openSession(), $this->openSession(), $this->openSession(), $this->openSession()];

        foreach ([BookingChannel::Counter, BookingChannel::Phone, BookingChannel::Walkin] as $i => $channel) {
            $this->staff($sessions[$i], channel: $channel);
        }

        // Three staff bookings today, zero self-service ones: the patient may still book online.
        $result = $this->selfService($sessions[3]);
        $this->assertSame(BookingChannel::Online, $result->appointment->channel);
        $this->assertSame(4, Appointment::query()->count());
    }

    public function test_the_cap_resets_at_the_clinic_midnight_not_at_utc_midnight(): void
    {
        $this->assertSame('Asia/Dhaka', Clock::timezone());
        Clock::freeze('2026-09-10 23:30');   // 17:30 UTC

        foreach ([$this->openSession(), $this->openSession(), $this->openSession()] as $session) {
            $this->selfService($session);
        }

        $this->assertThrows(fn () => $this->selfService($this->openSession()), SelfServiceLimitReached::class);

        // Forty minutes later it is a new clinic day (2026-09-11 00:10 Dhaka) but still 2026-09-10 in UTC:
        // a UTC-day counter would still refuse this number.
        Clock::freeze('2026-09-11 00:10');
        $this->assertSame('2026-09-10', Clock::now()->utc()->toDateString());
        $fresh = $this->selfService($this->openSession());
        $this->assertSame('2026-09-11', $fresh->appointment->scheduled_date?->toDateString());
        $this->assertSame(4, Appointment::query()->count());
    }

    public function test_zero_lifts_the_cap_and_the_setting_is_read_per_booking(): void
    {
        app(Settings::class)->set('booking.self_service_daily_limit', 0);
        $sessions = [$this->openSession(), $this->openSession(), $this->openSession(), $this->openSession(), $this->openSession()];

        foreach (array_slice($sessions, 0, 4) as $session) {
            $this->selfService($session);
        }

        $this->assertSame(4, Appointment::query()->count());

        app(Settings::class)->set('booking.self_service_daily_limit', 4);
        $this->assertThrows(fn () => $this->selfService($sessions[4]), SelfServiceLimitReached::class);
    }

    /** What the patient sees: a translated field error on the site form, a 422 with a stable code on the API. */
    public function test_the_public_forms_report_the_cap_as_a_translated_422(): void
    {
        $sessions = [$this->openSession(), $this->openSession(), $this->openSession(), $this->openSession()];
        $ulids = ['01J8ZK4V2Q3W5X6Y7Z8A9B0D0A', '01J8ZK4V2Q3W5X6Y7Z8A9B0D0B', '01J8ZK4V2Q3W5X6Y7Z8A9B0D0C', '01J8ZK4V2Q3W5X6Y7Z8A9B0D0D'];

        foreach (array_slice($sessions, 0, 3) as $i => $session) {
            $this->post(route('site.booking.store', [], false), ['session' => $session->public_id, 'mobile' => '01712345678', 'name' => 'Rahima', 'client_event_id' => $ulids[$i]])
                ->assertSessionHasNoErrors();
        }

        $this->post(route('site.booking.store', [], false), ['session' => $sessions[3]->public_id, 'mobile' => '01712345678', 'name' => 'Rahima', 'client_event_id' => $ulids[3]])
            ->assertSessionHasErrors(['domain' => __('booking.errors.self_service_daily_limit', ['limit' => '3'])]);

        $this->postJson(route('api.booking.public.store', [], false), ['session' => $sessions[3]->public_id, 'mobile' => '01712345678', 'patient' => ['name' => 'Rahima'], 'client_event_id' => $ulids[3]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'booking.self_service_limit_reached')
            ->assertJsonPath('message', __('booking.errors.self_service_daily_limit', ['limit' => '3']));

        $this->assertSame(3, Appointment::query()->count());
    }
}
