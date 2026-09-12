<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Queries;

use App\Domain\Prescription\Data\IssuedPrescriptionRef;
use App\Domain\Prescription\Enums\PrescriptionStatus;
use Illuminate\Support\Facades\DB;

/**
 * The Prescription module's read surface for "which of these encounters have an issued prescription to print?"
 * (BRIEF §5.G.4: the prescription is printed at the desk too). The sibling of VitalsStatusQuery, for the same
 * reason: the reception board asks by SERIAL, and it must not JOIN `visits`/`prescriptions` itself.
 *
 * "Issued" means `status = issued` — the live version of a chain. An amended original is `amended` and a voided one
 * `voided`; neither is a sheet the desk should hand to a patient, so neither is offered. Where a visit holds more
 * than one issued row the most recently issued wins (one `DISTINCT ON` per serial, one grouped query per board).
 */
final class IssuedPrescriptionQuery
{
    /**
     * @param  array<int, int>  $serialIds
     * @return array<int, IssuedPrescriptionRef> keyed by serial id; a serial without an issued prescription is absent
     */
    public function forSerials(array $serialIds): array
    {
        $ids = array_values(array_unique(array_filter($serialIds, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        /** @var array<int, IssuedPrescriptionRef> $out */
        $out = DB::connection('pgsql')->table('prescriptions', 'rx')
            ->join('visits as vs', 'vs.id', '=', 'rx.visit_id')
            ->whereIn('vs.serial_id', $ids)
            ->where('rx.status', PrescriptionStatus::Issued->value)
            ->selectRaw('distinct on (vs.serial_id) vs.serial_id as serial_id, rx.public_id as public_id, rx.verification_code as verification_code, rx.version as version')
            ->orderBy('vs.serial_id')->orderByDesc('rx.issued_at')->orderByDesc('rx.version')->orderByDesc('rx.id')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->serial_id => new IssuedPrescriptionRef(
                publicId: (string) $row->public_id,
                verificationCode: is_string($row->verification_code) ? $row->verification_code : null,
                version: (int) $row->version,
            )])
            ->all();

        return $out;
    }

    public function forSerial(int $serialId): IssuedPrescriptionRef
    {
        return $this->forSerials([$serialId])[$serialId] ?? IssuedPrescriptionRef::none();
    }
}
