<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Prescription\Enums\VisitStatus;
use App\Models\Tenant\Patient;
use App\Models\Tenant\User;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use App\Support\Clock;
use Database\Seeders\Tenant\VitalsDemoSeeder;
use Illuminate\Database\Eloquent\Collection;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

/**
 * The demo tenant's vitals history (BRIEF §5.H trend charts): a fresh install has to show a chart, and a chart is
 * only worth showing if the numbers under it hold together — BMI from the weight and height actually recorded, a
 * height that does not change between visits, readings taken before the doctor rather than after, and a recorder
 * who is the compounder rather than nobody.
 */
final class VitalsDemoSeederTest extends TestCase
{
    use SerialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    private function compounder(): User
    {
        $user = User::factory()->create(['email' => 'compounder@demo.test', 'default_branch_id' => $this->mainBranch()->id]);
        $user->assignRole(Role::Receptionist->value);

        return $user;
    }

    public function test_it_writes_a_consistent_history_and_is_idempotent(): void
    {
        $compounder = $this->compounder();
        $patient = Patient::factory()->create(['dob' => Clock::today()->subYears(44)->toDateString()]);

        foreach ([70, 42, 14] as $daysAgo) {
            Visit::factory()->create([
                'patient_id' => $patient->id,
                'status' => VisitStatus::Closed,
                'started_at' => Clock::now()->subDays($daysAgo),
            ]);
        }

        (new VitalsDemoSeeder)->run();

        /** @var Collection<int, Vital> $vitals */
        $vitals = Vital::query()->where('patient_id', $patient->id)->orderBy('recorded_at')->get();
        $this->assertCount(3, $vitals);
        $this->assertCount(1, $vitals->pluck('height_cm')->unique(), 'height is fixed for the person, so BMI tracks weight');

        foreach ($vitals as $vital) {
            $this->assertSame($compounder->id, $vital->recorded_by_user_id, 'the compounder takes the readings, not the doctor');
            $this->assertSame(Vital::computeBmi($vital->weight_kg, $vital->height_cm), $vital->bmi, 'BMI follows from this very row');
            $this->assertNotNull($vital->reviewed_by_doctor_at, 'a closed visit has been through the doctor');
            $this->assertGreaterThanOrEqual(40, (int) $vital->bp_systolic);
            $this->assertLessThan((int) $vital->bp_systolic, (int) $vital->bp_diastolic);
            $this->assertGreaterThanOrEqual(30.0, (float) $vital->temperature_c);
            $this->assertLessThanOrEqual(45.0, (float) $vital->temperature_c);

            $visit = Visit::query()->findOrFail($vital->visit_id);
            $this->assertTrue($vital->recorded_at->lessThan($visit->started_at), 'vitals are taken before the doctor sees the patient');
        }

        // Spread, not a single day: the whole point of a trend chart.
        $this->assertGreaterThan(
            30,
            $vitals->first()?->recorded_at->diffInDays($vitals->last()?->recorded_at) ?? 0,
        );

        (new VitalsDemoSeeder)->run();
        $this->assertSame(3, Vital::query()->where('patient_id', $patient->id)->count(), 're-seeding writes nothing twice');
    }

    public function test_it_gives_half_of_todays_waiting_room_a_reading_awaiting_the_doctor(): void
    {
        $this->compounder();
        $session = $this->openSession(10, 10, 5);
        $serials = [];

        foreach (range(1, 4) as $i) {
            $serial = $this->allocate($session, patientId: Patient::factory()->create()->id);
            $serial->forceFill(['status' => 'checked_in', 'checked_in_at' => Clock::now()->subMinutes(20)])->save();
            $serials[] = $serial;
        }

        (new VitalsDemoSeeder)->run();

        $withVitals = 0;

        foreach ($serials as $serial) {
            $visit = Visit::query()->where('serial_id', $serial->id)->first();

            if ($visit === null) {
                continue;
            }

            $vital = Vital::query()->where('visit_id', $visit->id)->firstOrFail();
            $this->assertNull($vital->reviewed_by_doctor_at, 'taken minutes ago: the doctor has not signed off yet');
            $this->assertFalse($vital->edited_by_doctor);
            $withVitals++;
        }

        $this->assertSame(2, $withVitals, 'half the waiting room is done and half is still due — both states on the board');
    }
}
