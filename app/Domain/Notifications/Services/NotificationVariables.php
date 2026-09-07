<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Support\Localised;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;

/**
 * Fills the documented placeholder catalogue (NotificationEvent::variables()) from the domain models. Every value
 * is already localised for the recipient here, so a template author writes `{{time}}` and a Bangla patient reads
 * "সকাল ১০:০০" while an English-preferring one reads "10:00 am" from the very same template row.
 *
 * Small per-instance caches keep a 300-patient delay fan-out from re-reading the same doctor and branch 300 times.
 */
final class NotificationVariables
{
    /** @var array<int, Doctor|null> */
    private array $doctors = [];

    /** @var array<int, Branch|null> */
    private array $branches = [];

    /** @return array<string, scalar|null> */
    public function base(?Patient $patient, Locale $locale): array
    {
        return [
            'clinic' => (string) (Tenancy::current()->name ?? ''),
            'patient_name' => $patient->name ?? '',
            'link' => '',
        ];
    }

    /**
     * serial / doctor / date / time / branch for a serial and the session it belongs to.
     *
     * @return array<string, scalar|null>
     */
    public function forSerial(Serial $serial, ?SessionInstance $session, Locale $locale): array
    {
        $session ??= SessionInstance::query()->find($serial->session_instance_id);
        $doctor = $session === null ? null : $this->doctor($session->doctor_id);
        $branch = $session === null ? null : $this->branch($session->branch_id);
        $start = $session->planned_start_at ?? null;

        return [
            'serial' => $serial->display_code,
            'doctor' => $this->doctorName($doctor, $locale),
            'branch' => $branch->name ?? '',
            'date' => Localised::date($session->session_date ?? $start, $locale),
            'time' => Localised::time($start, $locale),
        ];
    }

    /** @return array<string, scalar|null> */
    public function forSession(SessionInstance $session, Locale $locale): array
    {
        $doctor = $this->doctor($session->doctor_id);
        $branch = $this->branch($session->branch_id);

        return [
            'doctor' => $this->doctorName($doctor, $locale),
            'branch' => $branch->name ?? '',
            'date' => Localised::date($session->session_date, $locale),
            'time' => Localised::time($session->planned_start_at, $locale),
        ];
    }

    public function doctor(int $id): ?Doctor
    {
        return $this->doctors[$id] ??= Doctor::query()->find($id);
    }

    public function branch(?int $id): ?Branch
    {
        if ($id === null) {
            return null;
        }

        return $this->branches[$id] ??= Branch::query()->find($id);
    }

    /** A doctor's Bangla name when the patient reads Bangla and the clinic has entered one. */
    public function doctorName(?Doctor $doctor, Locale $locale): string
    {
        if ($doctor === null) {
            return '';
        }

        return $locale === Locale::Bn && $doctor->name_bn !== null && $doctor->name_bn !== '' ? $doctor->name_bn : $doctor->name;
    }

    public function forget(): void
    {
        $this->doctors = [];
        $this->branches = [];
    }
}
