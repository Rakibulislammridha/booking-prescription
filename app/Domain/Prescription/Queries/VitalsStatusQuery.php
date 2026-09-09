<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Queries;

use App\Domain\Prescription\Data\VitalsStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Prescription module's read surface for "which of these encounters already have vitals?" (SCHEMA §3.4).
 *
 * It exists so that the reception desk can show the compounder's progress on the board without Reception ever
 * touching `vitals` or `visits` — ARCHITECTURE §5.3 lets a module read another's models, but the shape of a
 * clinical table is not a contract, and a board that JOINs it would freeze it. The board asks by SERIAL, because
 * that is the only id the desk has: the visit is created lazily by StartVisit when the compounder opens the
 * screen, so a checked-in patient with no reading has no visit either.
 *
 * One grouped query for a whole board (`forSerials`); the single-row form is for the surfaces that present one
 * serial at a time (a replay result, a booking response).
 */
final class VitalsStatusQuery
{
    /**
     * @param  array<int, int>  $serialIds
     * @return array<int, VitalsStatus> keyed by serial id; a serial with no reading is absent, not zero-filled
     */
    public function forSerials(array $serialIds): array
    {
        $ids = array_values(array_unique(array_filter($serialIds, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        /** @var array<int, VitalsStatus> $out */
        $out = DB::connection('pgsql')->table('vitals', 'vt')
            ->join('visits as vs', 'vs.id', '=', 'vt.visit_id')
            ->whereIn('vs.serial_id', $ids)
            ->groupBy('vs.serial_id')
            ->selectRaw('vs.serial_id as serial_id, count(vt.id) as readings, max(vt.recorded_at) as recorded_at, count(vt.id) filter (where vt.reviewed_by_doctor_at is not null) as reviewed')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->serial_id => new VitalsStatus(
                readings: (int) $row->readings,
                recordedAt: is_string($row->recorded_at) ? CarbonImmutable::parse($row->recorded_at) : null,
                reviewedByDoctor: ((int) $row->reviewed) > 0,
            )])
            ->all();

        return $out;
    }

    public function forSerial(int $serialId): VitalsStatus
    {
        return $this->forSerials([$serialId])[$serialId] ?? VitalsStatus::none();
    }
}
