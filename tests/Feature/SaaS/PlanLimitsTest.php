<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Exceptions\PlanLimitExceeded;
use App\Domain\SaaS\Services\PlanLimits;
use App\Domain\SaaS\Services\UsageMeter;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientDocument;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\SaaS\Concerns\ControlsPlanLimits;
use Tests\TestCase;

/**
 * The boundary, for every limit a plan sells (BRIEF §5.M): the LAST allowed write succeeds and the NEXT one is
 * refused. Anything weaker than "n succeeds, n+1 fails" is not a limit, it is a suggestion.
 */
final class PlanLimitsTest extends TestCase
{
    use ControlsPlanLimits;

    public function test_branches_the_last_allowed_one_succeeds_and_the_next_is_refused(): void
    {
        $this->asTenant('a');
        $tenant = $this->tenant('a');
        $existing = Branch::query()->where('is_active', true)->count();
        $this->setUsage($tenant, UsageMetric::Branches, $existing);
        $this->setLimit($tenant, PlanFeatureKey::Branches, $existing + 1);

        Branch::factory()->create(['is_active' => true]);
        $this->assertSame($existing + 1, $this->usage($tenant, UsageMetric::Branches));

        $this->assertThrows(fn () => Branch::factory()->create(['is_active' => true]), PlanLimitExceeded::class);
        $this->assertSame($existing + 1, $this->usage($tenant, UsageMetric::Branches), 'a refused write must not leave its reservation behind');
    }

    public function test_doctors_are_capped_and_a_deactivated_doctor_frees_a_seat(): void
    {
        $this->asTenant('a');
        $tenant = $this->tenant('a');
        $this->setUsage($tenant, UsageMetric::Doctors, 0);
        $this->setLimit($tenant, PlanFeatureKey::Doctors, 2);

        $first = Doctor::factory()->create();
        Doctor::factory()->create();
        $this->assertSame(2, $this->usage($tenant, UsageMetric::Doctors));

        $this->assertThrows(fn () => Doctor::factory()->create(), PlanLimitExceeded::class);

        // Deactivating gives the seat back, and the seat can then be used.
        $first->forceFill(['is_active' => false])->save();
        $this->assertSame(1, $this->usage($tenant, UsageMetric::Doctors));
        Doctor::factory()->create();
        $this->assertSame(2, $this->usage($tenant, UsageMetric::Doctors));
        $this->assertThrows(fn () => Doctor::factory()->create(), PlanLimitExceeded::class);
    }

    public function test_a_deleted_doctor_frees_a_seat_and_a_restored_one_takes_it_back(): void
    {
        $this->asTenant('a');
        $tenant = $this->tenant('a');
        $this->setUsage($tenant, UsageMetric::Doctors, 0);
        $this->setLimit($tenant, PlanFeatureKey::Doctors, 1);

        $doctor = Doctor::factory()->create();
        $this->assertSame(1, $this->usage($tenant, UsageMetric::Doctors));

        $doctor->delete();
        $this->assertSame(0, $this->usage($tenant, UsageMetric::Doctors));

        $doctor->restore();
        $this->assertSame(1, $this->usage($tenant, UsageMetric::Doctors));
        $this->assertThrows(fn () => Doctor::factory()->create(), PlanLimitExceeded::class);
    }

    public function test_monthly_appointments_are_capped_and_a_draft_follow_up_is_not_metered(): void
    {
        $this->asTenant('a');
        $tenant = $this->tenant('a');
        $this->setUsage($tenant, UsageMetric::Appointments, 0);
        $this->setLimit($tenant, PlanFeatureKey::AppointmentsMonthly, 1);

        // A draft follow-up is an offer, not a booking (SCHEMA Appendix C item 10): it must not spend the quota.
        $draft = $this->makeAppointment(AppointmentStatus::Draft);
        $this->assertSame(0, $this->usage($tenant, UsageMetric::Appointments));

        $this->makeAppointment(AppointmentStatus::Confirmed);
        $this->assertSame(1, $this->usage($tenant, UsageMetric::Appointments));

        $this->assertThrows(fn () => $this->makeAppointment(AppointmentStatus::Confirmed), PlanLimitExceeded::class);

        // Confirming the draft is the moment it becomes a booking, and it is refused for the same reason.
        $this->assertThrows(fn () => $draft->forceFill(['status' => AppointmentStatus::Confirmed])->save(), PlanLimitExceeded::class);
    }

    public function test_the_monthly_meter_is_keyed_to_the_clinic_local_month(): void
    {
        $this->asTenant('a');
        $tenant = $this->tenant('a');
        $meter = app(UsageMeter::class);

        // 2026-03-31 18:30 UTC is 2026-04-01 00:30 in Dhaka: the quota rolls over on the CLINIC's calendar.
        $this->travelTo('2026-03-31 18:30:00');
        $this->assertSame('2026-04', $meter->period($tenant, UsageMetric::Appointments));
        $this->travelTo('2026-03-31 17:30:00');
        $this->assertSame('2026-03', $meter->period($tenant, UsageMetric::Appointments));
        $this->travelBack();
    }

    public function test_storage_bytes_are_metered_by_document_size_and_returned_on_delete(): void
    {
        $this->asTenant('a');
        $tenant = $this->tenant('a');
        $this->setUsage($tenant, UsageMetric::StorageBytes, 0);
        $this->setLimit($tenant, PlanFeatureKey::StorageBytes, 1_000_000);
        $patient = Patient::factory()->create();

        $document = $this->makeDocument($patient->id, 600_000);
        $this->assertSame(600_000, $this->usage($tenant, UsageMetric::StorageBytes));

        $this->assertThrows(fn () => $this->makeDocument($patient->id, 600_000), PlanLimitExceeded::class);
        $this->assertSame(600_000, $this->usage($tenant, UsageMetric::StorageBytes));

        $document->delete();
        $this->assertSame(0, $this->usage($tenant, UsageMetric::StorageBytes));
        $this->makeDocument($patient->id, 900_000);
        $this->assertSame(900_000, $this->usage($tenant, UsageMetric::StorageBytes));
    }

    /**
     * SMS is the one cap that must NOT throw (SCHEMA §5.8): a patient's booking must not fail because the clinic
     * ran out of credits. The message is written `failed` with `sms_credits_exhausted` and shows up in the log.
     */
    public function test_sms_credits_exhaust_into_a_failed_notification_rather_than_an_exception(): void
    {
        $this->asTenant('a');
        $tenant = $this->tenant('a');
        $this->setUsage($tenant, UsageMetric::SmsCredits, 0);
        $this->setLimit($tenant, PlanFeatureKey::SmsCreditsMonthly, 2);

        $first = $this->makeSms(2);
        $this->assertSame(NotificationStatus::Queued, $first->status);
        $this->assertSame(2, $this->usage($tenant, UsageMetric::SmsCredits));

        $second = $this->makeSms(1);
        $this->assertSame(NotificationStatus::Failed, $second->status);
        $this->assertSame('sms_credits_exhausted', $second->last_error);
        $this->assertSame(2, $this->usage($tenant, UsageMetric::SmsCredits), 'a refused SMS spends nothing');

        // Other channels are unaffected — only SMS costs credits.
        $email = $this->makeSms(1, NotificationChannel::Email);
        $this->assertSame(NotificationStatus::Queued, $email->status);
    }

    public function test_an_unlimited_limit_never_refuses_and_a_zero_limit_always_does(): void
    {
        $this->asTenant('a');
        $tenant = $this->tenant('a');
        $limits = app(PlanLimits::class);

        $this->setLimit($tenant, PlanFeatureKey::Doctors, null);
        $this->assertNull($limits->limit($tenant, UsageMetric::Doctors));
        $this->assertNull($limits->remaining($tenant, UsageMetric::Doctors));
        Doctor::factory()->count(3)->create();

        $this->setLimit($tenant, PlanFeatureKey::Doctors, 0);
        $this->assertThrows(fn () => Doctor::factory()->create(), PlanLimitExceeded::class);
    }

    public function test_the_refusal_names_the_limit_and_the_upgrade_path_and_carries_a_402_domain_code(): void
    {
        $this->asTenant('a');
        $tenant = $this->tenant('a');
        $this->setUsage($tenant, UsageMetric::Doctors, 0);
        $this->setLimit($tenant, PlanFeatureKey::Doctors, 0);

        try {
            Doctor::factory()->create();
            $this->fail('expected PlanLimitExceeded');
        } catch (PlanLimitExceeded $e) {
            $this->assertSame('saas.limit.doctors', $e->code());
            $this->assertSame(402, $e->status());
            $this->assertStringContainsString(__('saas.metric.doctors'), $e->getMessage());
            $this->assertStringContainsString(app(PlanLimits::class)->entitlements($tenant)->planName, $e->getMessage());
        }
    }

    public function test_counters_are_per_tenant(): void
    {
        $tenantA = $this->tenant('a');
        $tenantB = $this->tenant('b');
        $this->setUsage($tenantA, UsageMetric::Doctors, 0);
        $this->setUsage($tenantB, UsageMetric::Doctors, 0);
        $this->setLimit($tenantA, PlanFeatureKey::Doctors, 1);
        $this->setLimit($tenantB, PlanFeatureKey::Doctors, 1);

        $this->asTenant('a');
        Doctor::factory()->create();
        $this->assertThrows(fn () => Doctor::factory()->create(), PlanLimitExceeded::class);

        $this->asTenant('b');
        $this->assertSame(0, $this->usage($tenantB, UsageMetric::Doctors), 'tenant b must not see tenant a usage');
        Doctor::factory()->create();
        $this->assertSame(1, $this->usage($tenantB, UsageMetric::Doctors));
    }

    public function test_the_nightly_recount_repairs_drift_from_a_raw_write(): void
    {
        $this->asTenant('a');
        $tenant = $this->tenant('a');
        $this->setLimit($tenant, PlanFeatureKey::Doctors, null);
        Doctor::factory()->count(2)->create();
        $real = (int) DB::table('doctors')->where('is_active', true)->whereNull('deleted_at')->count();

        // A raw write bypasses Eloquent and therefore the observer — the exact drift the recount exists for.
        $this->setUsage($tenant, UsageMetric::Doctors, 999);
        $this->assertSame(999, $this->usage($tenant, UsageMetric::Doctors));

        Tenancy::end();
        $this->artisan('saas:recount-usage', ['--tenant' => ['test-a']])->assertSuccessful();
        $this->assertSame($real, $this->usage($tenant, UsageMetric::Doctors));
    }

    private function makeAppointment(AppointmentStatus $status): Appointment
    {
        return Appointment::factory()->create(['status' => $status]);
    }

    private function makeDocument(int $patientId, int $bytes): PatientDocument
    {
        return PatientDocument::query()->create([
            'patient_id' => $patientId,
            'type' => 'other',
            'title' => 'Scan',
            'original_filename' => 'scan.pdf',
            'storage_disk' => 'uploads',
            'storage_path' => 'tenants/9001/documents/'.Str::ulid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $bytes,
            'uploaded_by_type' => 'staff',
            'uploaded_by_id' => 1,
        ]);
    }

    private function makeSms(int $segments, NotificationChannel $channel = NotificationChannel::Sms): Notification
    {
        return Notification::query()->create([
            'event_key' => NotificationEvent::BookingConfirmed,
            'channel' => $channel,
            'recipient' => '+8801700000000',
            'locale' => 'bn',
            'body' => 'x',
            'payload' => [],
            'status' => NotificationStatus::Queued,
            'attempts' => 0,
            'segments' => $segments,
        ]);
    }
}
