<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Booking\Services\KioskLink;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Patients\Enums\OtpPurpose;
use App\Domain\Patients\Services\OtpService;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorLeave;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Holiday;
use App\Models\Tenant\PatientOtpCode;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\Specialty;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

/** The public booking site (routes/site/booking.php), the kiosk signed URL and the public booking API. */
final class SiteBookingTest extends TestCase
{
    use SerialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /** @param  array<string, mixed>  $params */
    private function url(string $name, array $params = []): string
    {
        return route($name, $params, false);
    }

    public function test_index_searches_doctors_by_specialty_name_and_day(): void
    {
        $cardio = Specialty::factory()->create(['name' => 'Cardiology', 'slug' => 'cardiology']);
        $doctor = $this->doctorWithTemplate();
        $doctor->specialties()->attach($cardio->id, ['is_primary' => true]);
        $doctor->forceFill(['name' => 'Dr. Rahman Search'])->save();
        Doctor::factory()->complete()->create(['name' => 'Dr. Hidden', 'accepts_online_booking' => false]);
        $noTemplate = Doctor::factory()->complete()->create(['name' => 'Dr. Weekend']);

        $this->get($this->url('site.booking.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Booking/Index')->has('doctors', 2)->has('specialties', 1));
        $this->get($this->url('site.booking.index', ['specialty' => 'cardiology']))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Booking/Index')->has('doctors', 1)->where('doctors.0.slug', $doctor->slug)->where('doctors.0.weekdays', [0, 1, 2, 3, 4, 5, 6]));
        $this->get($this->url('site.booking.index', ['q' => 'rahman']))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('doctors', 1));
        $this->get($this->url('site.booking.index', ['day' => 2]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('doctors', 1)->where('doctors.0.public_id', $doctor->public_id));
        $this->assertTrue($noTemplate->accepts_online_booking);
    }

    public function test_doctor_page_and_online_booking_with_otp_end_to_end(): void
    {
        $doctor = $this->doctorWithTemplate(10, 10, 5);
        app(Settings::class)->set('kiosk.otp_required', true);   // the opt-in verified flow, unchanged

        $this->get($this->url('site.booking.doctor', ['doctor' => $doctor->slug]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Booking/Doctor')->where('doctor.slug', $doctor->slug)->where('otp_required', true)->where('online_payment_enabled', false)->where('channel', 'online'));

        $availability = $this->getJson($this->url('api.scheduling.availability', ['slug' => $doctor->slug]))->assertOk()->json();
        $session = $availability['days'][0]['sessions'][0];
        $this->assertSame(10, $session['online_remaining']);

        $this->postJson($this->url('site.booking.otp'), ['mobile' => '01712345678'])->assertOk()->assertJsonPath('sent', true);
        $this->postJson($this->url('site.booking.otp'), ['mobile' => 'not-a-number'])->assertStatus(422);

        $ulid = '01J8ZK4V2Q3W5X6Y7Z8A9B0C2A';
        $this->post($this->url('site.booking.store'), ['session' => $session['public_id'], 'mobile' => '01712345678', 'otp' => '999999', 'name' => 'Rahima', 'sex' => 'f', 'age_years' => 54, 'client_event_id' => $ulid])
            ->assertSessionHasErrors('otp');

        $this->postJson($this->url('site.booking.otp'), ['mobile' => '01712345678'])->assertStatus(422);   // throttled 60 s
        $this->travel(61)->seconds();
        $this->postJson($this->url('site.booking.otp'), ['mobile' => '01712345678'])->assertOk();

        $response = $this->post($this->url('site.booking.store'), ['session' => $session['public_id'], 'mobile' => '01712345678', 'otp' => '000000', 'name' => 'Rahima', 'sex' => 'f', 'age_years' => 54, 'client_event_id' => $ulid]);
        $appointment = Appointment::query()->where('client_event_id', $ulid)->firstOrFail();
        $response->assertRedirect($this->url('site.booking.confirmed', ['appointment' => $appointment->public_id]));
        $serial = Serial::query()->findOrFail($appointment->serial_id);
        $this->assertSame('online', $serial->pool->value);
        $this->assertSame('A-011', $serial->display_code);

        $this->get($this->url('site.booking.confirmed', ['appointment' => $appointment->public_id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Booking/Confirmed')
                ->where('appointment.serial.display_code', 'A-011')
                ->where('queue_url', '/q/'.$doctor->slug.'/today?s='.$serial->public_id)
                ->where('pay_at_counter', true));

        $this->getJson($this->url('api.scheduling.availability', ['slug' => $doctor->slug]))->assertOk()->assertJsonPath('days.0.sessions.0.online_remaining', 9);
    }

    /**
     * What the day strip and the session cards draw (site/Components/Booking): why a day has no session (holiday,
     * leave, or simply not in the weekly template), each session's online quota next to its remaining, and — only
     * while a session is running — the serial it is serving.
     */
    public function test_availability_says_why_a_day_is_closed_and_carries_quota_and_now_serving(): void
    {
        $doctor = $this->doctorWithTemplate(10, 10, 5);
        $today = $this->today();
        Holiday::factory()->create(['holiday_date' => $today->addDay()->toDateString()]);
        DoctorLeave::factory()->create(['doctor_id' => $doctor->id, 'starts_on' => $today->addDays(2)->toDateString(), 'ends_on' => $today->addDays(3)->toDateString()]);
        $onceAWeek = Doctor::factory()->complete()->create();
        DoctorSchedule::factory()->quotas(10, 10, 5)->create(['doctor_id' => $onceAWeek->id, 'branch_id' => $this->mainBranch()->id, 'weekday' => $today->dayOfWeek, 'session_code' => 'A']);

        $days = $this->getJson($this->url('api.scheduling.availability', ['slug' => $doctor->slug]))->assertOk()->json('days');
        $this->assertCount(14, $days);
        $this->assertNull($days[0]['closed']);
        $this->assertSame(['status' => 'scheduled', 'online_remaining' => 10, 'online_quota' => 10, 'slot_minutes' => null, 'now_serving' => null], array_intersect_key($days[0]['sessions'][0], array_flip(['status', 'online_remaining', 'online_quota', 'slot_minutes', 'now_serving'])));
        $this->assertSame(['holiday', []], [$days[1]['closed'], $days[1]['sessions']]);
        $this->assertSame(['leave', 'leave', null], [$days[2]['closed'], $days[3]['closed'], $days[4]['closed']]);

        $weekly = $this->getJson($this->url('api.scheduling.availability', ['slug' => $onceAWeek->slug]))->assertOk()->json('days');
        $this->assertSame([null, 'holiday', 'off', 'off', 'off', 'off', 'off', null], array_column(array_slice($weekly, 0, 8), 'closed'));

        // The desk calls a serial: the card can say "now serving A-001" without a second request.
        $session = SessionInstance::query()->where('public_id', $days[0]['sessions'][0]['public_id'])->firstOrFail();
        $serial = $this->allocate($session);
        $session->forceFill(['status' => SessionStatus::Running, 'now_serving_serial_id' => $serial->id])->save();
        $this->getJson($this->url('api.scheduling.availability', ['slug' => $doctor->slug]))->assertOk()
            ->assertJsonPath('days.0.sessions.0.status', 'running')
            ->assertJsonPath('days.0.sessions.0.now_serving', $serial->display_code)
            ->assertJsonPath('days.0.sessions.0.online_remaining', 10);   // the counter serial did not touch the online pool
    }

    /**
     * The default flow (BRIEF §5.C): mobile + name → session → serial → confirmation. No OTP screen, no OTP request,
     * no `patient_otp_codes` row — for the online site and for the kiosk / QR page alike, which post the same form.
     */
    public function test_online_and_kiosk_booking_need_no_otp_by_default(): void
    {
        $doctor = $this->doctorWithTemplate(10, 10, 5);
        $branch = $this->mainBranch();

        $this->get($this->url('site.booking.doctor', ['doctor' => $doctor->slug]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Booking/Doctor')->where('otp_required', false)->where('channel', 'online'));

        $session = $this->getJson($this->url('api.scheduling.availability', ['slug' => $doctor->slug]))->assertOk()->json('days.0.sessions.0');
        $ulid = '01J8ZK4V2Q3W5X6Y7Z8A9B0C3A';
        $response = $this->post($this->url('site.booking.store'), ['session' => $session['public_id'], 'mobile' => '01712345678', 'name' => 'Rahima', 'sex' => 'f', 'age_years' => 54, 'client_event_id' => $ulid]);

        $appointment = Appointment::query()->where('client_event_id', $ulid)->firstOrFail();
        $response->assertSessionHasNoErrors()->assertRedirect($this->url('site.booking.confirmed', ['appointment' => $appointment->public_id]));
        $this->assertSame('online', $appointment->channel->value);
        $this->assertSame('A-011', Serial::query()->findOrFail($appointment->serial_id)->display_code);
        $this->assertSame(0, PatientOtpCode::query()->count(), 'no code was issued or stored');
        $this->get($this->url('site.booking.confirmed', ['appointment' => $appointment->public_id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Booking/Confirmed')->where('appointment.serial.display_code', 'A-011'));

        // Kiosk / QR: the same setting, the same form, source kiosk on the online pool.
        $kiosk = $this->post($this->url('site.booking.store'), ['session' => $session['public_id'], 'channel' => 'kiosk', 'kiosk_branch' => $branch->public_id, 'mobile' => '01712345679', 'name' => 'Karim', 'client_event_id' => '01J8ZK4V2Q3W5X6Y7Z8A9B0C3B']);
        $kioskAppointment = Appointment::query()->where('client_event_id', '01J8ZK4V2Q3W5X6Y7Z8A9B0C3B')->firstOrFail();
        $kiosk->assertSessionHasNoErrors()->assertRedirect($this->url('site.booking.confirmed', ['appointment' => $kioskAppointment->public_id]));
        $this->assertSame('kiosk', $kioskAppointment->channel->value);
        $this->assertSame('kiosk', Serial::query()->findOrFail($kioskAppointment->serial_id)->source->value);
        $this->assertSame(0, PatientOtpCode::query()->count());
    }

    /** With the setting on, nothing a client posts stands in for verification: the server decides. */
    public function test_a_forged_verified_flag_does_not_skip_the_otp_when_the_clinic_requires_it(): void
    {
        $doctor = $this->doctorWithTemplate(10, 10, 5);
        app(Settings::class)->set('kiosk.otp_required', true);
        $session = $this->getJson($this->url('api.scheduling.availability', ['slug' => $doctor->slug]))->assertOk()->json('days.0.sessions.0');
        $forged = ['session' => $session['public_id'], 'mobile' => '01712345678', 'name' => 'Rahima', 'client_event_id' => '01J8ZK4V2Q3W5X6Y7Z8A9B0C3C', 'otp_verified' => true, 'otpVerified' => 1];

        $this->post($this->url('site.booking.store'), $forged)->assertSessionHasErrors('otp');
        $this->post($this->url('site.booking.store'), $forged + ['otp' => '000000'])->assertSessionHasErrors('otp');   // no code was ever issued
        $this->postJson($this->url('api.booking.public.store'), ['doctor_slug' => $doctor->slug, 'date' => $this->today()->toDateString(), 'session_code' => 'A', 'mobile' => '01712345678', 'patient' => ['name' => 'Rahima'], 'client_event_id' => '01J8ZK4V2Q3W5X6Y7Z8A9B0C3D', 'otp_verified' => true])
            ->assertStatus(422)->assertJsonValidationErrors('otp');
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_public_api_booking_and_double_submit(): void
    {
        $doctor = $this->doctorWithTemplate(10, 10, 5);
        app(Settings::class)->set('kiosk.otp_required', false);
        $ulid = '01J8ZK4V2Q3W5X6Y7Z8A9B0C2B';
        $body = ['doctor_slug' => $doctor->slug, 'date' => $this->today()->toDateString(), 'session_code' => 'A', 'mobile' => '01798765432', 'patient' => ['name' => 'Api Patient', 'sex' => 'm', 'age_years' => 33], 'client_event_id' => $ulid];

        $first = $this->postJson($this->url('api.booking.public.store'), $body)->assertCreated()
            ->assertJsonPath('serial.display_code', 'A-011')->assertJsonPath('appointment.channel', 'online')->assertJsonPath('replayed', false);
        $this->postJson($this->url('api.booking.public.store'), $body)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('serial.public_id', $first->json('serial.public_id'));
        $this->assertSame(1, Appointment::query()->where('client_event_id', $ulid)->count());

        app(Settings::class)->set('kiosk.otp_required', true);
        $this->postJson($this->url('api.booking.public.store'), array_merge($body, ['client_event_id' => '01J8ZK4V2Q3W5X6Y7Z8A9B0C2C', 'mobile' => '01798765433']))->assertStatus(422)->assertJsonValidationErrors('otp');
        app(OtpService::class)->request('01798765433', OtpPurpose::Booking);
        $this->postJson($this->url('api.booking.public.store'), array_merge($body, ['client_event_id' => '01J8ZK4V2Q3W5X6Y7Z8A9B0C2C', 'mobile' => '01798765433', 'otp' => '000000']))->assertCreated();
    }

    public function test_kiosk_signed_url_opens_the_prefilled_page_and_rejects_tampering(): void
    {
        $doctor = $this->doctorWithTemplate(10, 10, 5);
        $branch = $this->mainBranch();
        $this->actingAsStaff(Role::Receptionist);
        $url = (string) $this->getJson($this->url('panel.reception.kiosk_url'))->assertOk()->json('url');
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('test-a.bp.test', $url, 'signed against the tenant host');
        $this->assertSame(KioskLink::HOURS, 12);
        $path = (string) parse_url($url, PHP_URL_PATH).'?'.(string) parse_url($url, PHP_URL_QUERY);
        auth('web')->logout();

        $this->get($path)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Booking/Doctor')->where('channel', 'kiosk')->where('kiosk.branch', $branch->public_id)->has('kiosk.sessions', 1)->where('kiosk.sessions.0.doctor.slug', $doctor->slug));

        $this->get(str_replace('signature=', 'signature=deadbeef', $path))->assertForbidden();
        $this->get($this->url('site.booking.kiosk', ['branch' => $branch->public_id]))->assertForbidden();

        $this->travel(13)->hours();
        $this->get($path)->assertForbidden();
    }
}
