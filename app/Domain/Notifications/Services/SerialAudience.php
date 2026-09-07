<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Walks a serial query in chunks and hands each row its patient.
 *
 * `Serial` carries `patient_id` but the Serials module gives it no `patient()` relation (that model is theirs), so
 * the fan-outs would otherwise do one query per patient — 300 queries to tell 300 people the doctor is late. This
 * loads one batch of patients per chunk instead, and is the only place that has to know about the gap.
 *
 * @phpstan-type SerialPatientCallback Closure(Serial, Patient): void
 */
final class SerialAudience
{
    public const CHUNK = 200;

    /**
     * @param  Builder<Serial>  $query
     * @param  Closure(Serial, Patient): void  $callback
     * @param  int|null  $max  stop after this many serials (chunkById overrides any ->limit(), so the cap lives here)
     */
    public function each(Builder $query, Closure $callback, ?int $max = null): void
    {
        $seen = 0;

        $query->whereNotNull('patient_id')->chunkById(self::CHUNK, function (EloquentCollection $serials) use ($callback, $max, &$seen): bool {
            /** @var EloquentCollection<int, Serial> $serials */
            $patients = Patient::query()
                ->whereIn('id', $serials->pluck('patient_id')->filter()->unique()->all())
                ->get()
                ->keyBy('id');

            foreach ($serials as $serial) {
                $patient = $patients->get($serial->patient_id);

                if ($patient instanceof Patient) {
                    $callback($serial, $patient);
                }

                $seen++;

                if ($max !== null && $seen >= $max) {
                    return false;
                }
            }

            return true;
        });
    }
}
