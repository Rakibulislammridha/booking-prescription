<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Prescription\Enums\VisitStatus;
use App\Domain\Prescription\Enums\VisitType;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open OPD visit for a fresh patient and a complete doctor (profile + pad settings). Use ->fromSerial($serial)
 * to link the serial/session/appointment the way StartVisit does.
 *
 * @extends Factory<Visit>
 */
final class VisitFactory extends Factory
{
    protected $model = Visit::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'appointment_id' => null,
            'serial_id' => null,
            'session_instance_id' => null,
            'patient_id' => Patient::factory(),
            'doctor_id' => Doctor::factory()->complete(),
            'branch_id' => fn () => Branch::query()->where('is_main', true)->value('id') ?? Branch::factory()->main()->create()->id,
            'type' => VisitType::Opd,
            'status' => VisitStatus::Open,
            'started_at' => now(),
            'chief_complaints' => [],
            'examination_findings' => null,
            'diagnoses' => [],
            'follow_up_on' => null,
            'follow_up_note' => null,
        ];
    }

    public function fromSerial(Serial $serial): static
    {
        $session = $serial->sessionInstance;

        return $this->state(fn () => [
            'serial_id' => $serial->id,
            'session_instance_id' => $serial->session_instance_id,
            'appointment_id' => $serial->appointment_id,
            'patient_id' => $serial->patient_id,
            'doctor_id' => $session->doctor_id,
            'branch_id' => $session->branch_id,
        ]);
    }

    /** A routine URTI encounter with complaints, findings and a coded diagnosis. */
    public function urti(): static
    {
        return $this->state(fn () => [
            'chief_complaints' => [
                ['text' => 'Fever', 'text_bn' => 'জ্বর', 'duration' => '3d', 'sort' => 0],
                ['text' => 'Cough', 'text_bn' => 'কাশি', 'duration' => '2d', 'sort' => 1],
            ],
            'examination_findings' => 'Throat congested; Temp 100.4F',
            'diagnoses' => [['icd10_code' => 'J06.9', 'title' => 'Acute upper respiratory infection, unspecified', 'kind' => 'provisional', 'sort' => 0]],
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => VisitStatus::Closed, 'ended_at' => now()]);
    }
}
