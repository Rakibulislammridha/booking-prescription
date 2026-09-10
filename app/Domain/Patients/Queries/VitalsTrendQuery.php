<?php

declare(strict_types=1);

namespace App\Domain\Patients\Queries;

use App\Domain\Prescription\Support\Temperature;
use App\Models\Tenant\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRESCRIPTION.md §8 vitals trend (last N rows, oldest first, for sparklines). Reads the Prescription module's
 * `vitals` table (SCHEMA §3.4) through the query builder; returns [] until that table exists.
 */
final class VitalsTrendQuery
{
    public const COLUMNS = ['bp_systolic', 'bp_diastolic', 'pulse_bpm', 'temperature_c', 'spo2_percent', 'respiratory_rate', 'weight_kg', 'height_cm', 'bmi', 'blood_glucose_mgdl'];

    private ?bool $available = null;

    public function available(): bool
    {
        return $this->available ??= Schema::connection('pgsql')->hasTable('vitals');
    }

    /** @return array<int, array<string, mixed>> */
    public function for(Patient $patient, int $limit = 12): array
    {
        if (! $this->available()) {
            return [];
        }

        $rows = DB::connection('pgsql')->table('vitals')
            ->where('patient_id', $patient->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get(['id', 'visit_id', 'recorded_at', ...self::COLUMNS]);

        return $rows->reverse()->values()->map(fn (object $r): array => [
            'id' => (int) $r->id,
            'visit_id' => $r->visit_id === null ? null : (int) $r->visit_id,
            'recorded_at' => (string) $r->recorded_at,
            ...array_combine(self::COLUMNS, array_map(fn (string $c) => $r->{$c} === null ? null : (float) $r->{$c}, self::COLUMNS)),
            // The stored unit is °C (SCHEMA §3.4); the chart, its table and the record card read °F.
            'temperature_f' => $r->temperature_c === null ? null : Temperature::cToF((float) $r->temperature_c),
        ])->all();
    }
}
