<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Patients\Contracts\OtpSender;
use App\Domain\Patients\Enums\OtpPurpose;
use App\Domain\Patients\Exceptions\OtpAttemptsExceeded;
use App\Domain\Patients\Exceptions\OtpExpired;
use App\Domain\Patients\Exceptions\OtpInvalid;
use App\Domain\Patients\Exceptions\OtpThrottled;
use App\Domain\Patients\Services\NullOtpSender;
use App\Domain\Patients\Services\OtpService;
use App\Http\Controllers\Site\Portal\OtpController;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientOtpCode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class OtpFlowTest extends TestCase
{
    private NullOtpSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = new NullOtpSender;
        $this->app->instance(OtpSender::class, $this->sender);
        $this->asTenant('a');
    }

    public function test_request_stores_a_hashed_code_with_expiry_and_delivers_through_the_sender(): void
    {
        $patient = Patient::factory()->create(['mobile' => '+8801712345678']);

        $otp = app(OtpService::class)->request('01712345678', OtpPurpose::Login, '10.0.0.1');

        $this->assertSame('+8801712345678', $otp->mobile);
        $this->assertSame($patient->id, $otp->patient_id);
        $this->assertSame(0, $otp->attempts);
        $this->assertTrue($otp->expires_at->between(now()->addSeconds(290), now()->addSeconds(310)));
        $this->assertCount(1, $this->sender->sent);
        $this->assertSame('000000', $this->sender->sent[0]['code'], 'OTP_FIXED_CODE in testing');
        $this->assertNotSame('000000', $otp->code_hash);
        $this->assertTrue(Hash::check('000000', $otp->code_hash));
        $this->assertArrayNotHasKey('code_hash', $otp->toArray());
    }

    public function test_resend_is_throttled_for_sixty_seconds(): void
    {
        $service = app(OtpService::class);
        $service->request('01712345678');

        $this->assertGreaterThan(0, $service->resendAvailableIn('01712345678'));
        $this->expectException(OtpThrottled::class);
        $service->request('01712345678');
    }

    public function test_verify_consumes_the_latest_code_and_rejects_reuse(): void
    {
        $service = app(OtpService::class);
        $service->request('01712345678');

        $verified = $service->verify('01712345678', '000000');
        $this->assertNotNull($verified->consumed_at);
        $this->assertSame(0, $service->resendAvailableIn('01712345678'), 'throttle cleared on success');

        $this->expectException(OtpInvalid::class);
        $service->verify('01712345678', '000000');
    }

    public function test_wrong_codes_count_attempts_and_lock_after_five(): void
    {
        $service = app(OtpService::class);
        $otp = $service->request('01712345678');

        foreach (range(1, 4) as $n) {
            try {
                $service->verify('01712345678', '111111');
                $this->fail('expected OtpInvalid');
            } catch (OtpInvalid) {
                $this->assertSame($n, $otp->fresh()->attempts);
            }
        }

        try {
            $service->verify('01712345678', '111111');
            $this->fail('expected OtpAttemptsExceeded');
        } catch (OtpAttemptsExceeded) {
            $this->assertSame(5, $otp->fresh()->attempts);
        }

        $this->expectException(OtpAttemptsExceeded::class);
        $service->verify('01712345678', '000000');   // even the right code is refused once locked
    }

    public function test_expired_codes_are_refused(): void
    {
        PatientOtpCode::factory()->forCode('000000')->expired()->create(['mobile' => '+8801712345678']);

        $this->expectException(OtpExpired::class);
        app(OtpService::class)->verify('01712345678', '000000');
    }

    public function test_prune_removes_rows_older_than_a_day(): void
    {
        PatientOtpCode::factory()->create(['created_at' => now()->subHours(25)]);
        PatientOtpCode::factory()->create(['created_at' => now()->subHours(2)]);

        $this->assertSame(1, app(OtpService::class)->prune());
        $this->assertSame(1, PatientOtpCode::query()->count());
    }

    public function test_the_portal_login_flow_signs_the_mobile_owner_in_on_the_patient_guard(): void
    {
        $owner = Patient::factory()->create(['mobile' => '+8801712345678']);
        $child = Patient::factory()->dependentOf($owner)->create(['name' => 'Child']);

        $this->get('/portal/login')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Portal/Login'));

        $this->post('/portal/otp', ['mobile' => '017 1234 5678'])->assertRedirect('/portal/verify');
        $this->assertCount(1, $this->sender->sent);

        $this->get('/portal/verify')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Portal/Verify')->where('mobile', '01712345678'));

        $this->post('/portal/otp/verify', ['mobile' => '01712345678', 'code' => '000000'])->assertRedirect('/portal');

        $this->assertTrue(Auth::guard('patient')->check());
        $this->assertTrue(Auth::guard('patient')->user()?->is($owner) ?? false);
        $this->assertFalse(Auth::guard('web')->check());
        $this->assertSame($owner->public_id, session(OtpController::SESSION_ACTING_FOR));
        $this->assertAudited(AuditAction::Login, $owner, ['guard' => 'patient']);

        $this->get('/portal')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Portal/Home')
            ->where('patient.public_id', $owner->public_id)
            ->has('family', 2)
            ->where('acting_for.public_id', $owner->public_id)
            ->has('timeline.data'));
        $this->assertAudited(AuditAction::View, $owner, ['screen' => 'site.portal.home']);

        $this->patch('/portal/acting-for', ['patient' => $child->public_id])->assertRedirect('/portal');
        $this->get('/portal')->assertInertia(fn (AssertableInertia $p) => $p->where('acting_for.public_id', $child->public_id));
        $this->assertAudited(AuditAction::View, $child, ['screen' => 'site.portal.home']);

        $stranger = Patient::factory()->create();
        $this->patch('/portal/acting-for', ['patient' => $stranger->public_id])->assertSessionHasErrors('domain');

        $this->post('/portal/logout')->assertRedirect('/portal/login');
        $this->assertFalse(Auth::guard('patient')->check());
        $this->assertAudited(AuditAction::Logout, $owner, ['guard' => 'patient']);
    }

    public function test_wrong_and_expired_codes_surface_as_field_errors(): void
    {
        Patient::factory()->create(['mobile' => '+8801712345678']);
        $this->post('/portal/otp', ['mobile' => '01712345678']);

        $this->post('/portal/otp/verify', ['mobile' => '01712345678', 'code' => '999999'])->assertSessionHasErrors('code');
        $this->assertFalse(Auth::guard('patient')->check());

        $this->travel(6)->minutes();
        $this->post('/portal/otp/verify', ['mobile' => '01712345678', 'code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse(Auth::guard('patient')->check());
    }

    public function test_unknown_mobiles_and_invalid_numbers_get_no_code(): void
    {
        $this->post('/portal/otp', ['mobile' => '01712345678'])->assertSessionHasErrors('mobile');
        $this->post('/portal/otp', ['mobile' => '12345'])->assertSessionHasErrors('mobile');
        $this->assertCount(0, $this->sender->sent);
        $this->assertSame(0, PatientOtpCode::query()->count());
    }

    public function test_requests_are_rate_limited_per_ip(): void
    {
        Patient::factory()->create(['mobile' => '+8801712345678']);

        foreach (range(1, 5) as $i) {
            $this->post('/portal/otp', ['mobile' => '01712345678'])->assertStatus(302);
        }

        $this->post('/portal/otp', ['mobile' => '01712345678'])->assertStatus(429);
    }

    public function test_a_patient_of_another_tenant_cannot_log_in_here(): void
    {
        $this->asTenant('b');
        Patient::factory()->create(['mobile' => '+8801712345678']);

        $this->asTenant('a');
        $this->post('/portal/otp', ['mobile' => '01712345678'])->assertSessionHasErrors('mobile');
        $this->assertSame(0, PatientOtpCode::query()->count());

        // Even a forged code row in tenant A for that mobile cannot resolve a tenant B patient.
        PatientOtpCode::factory()->forCode('000000')->create(['mobile' => '+8801712345678']);
        $this->post('/portal/otp/verify', ['mobile' => '01712345678', 'code' => '000000'])->assertSessionHasErrors('mobile');
        $this->assertFalse(Auth::guard('patient')->check());
    }

    public function test_the_portal_home_requires_the_patient_guard(): void
    {
        $this->get('/portal')->assertRedirect('/portal/login');
        $this->actingAsStaff();
        $this->get('/portal')->assertRedirect('/portal/login');
    }
}
