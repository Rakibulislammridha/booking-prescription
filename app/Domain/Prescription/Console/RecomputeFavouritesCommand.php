<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Console;

use App\Domain\Prescription\Services\DoctorLearningCache;
use App\Models\Tenant\DoctorDrugUsage;
use App\Models\Tenant\DoctorFavourite;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;

/**
 * prescriptions:recompute-favourites (nightly via `tenants:run`, PRESCRIPTION.md §3.5): rewrites doctor_favourites.rank
 * from the trailing 6 months of doctor_drug_usage — pinned rows first, then by use count; learned rows without recent
 * usage sink to the bottom. Runs inside a tenant context.
 */
final class RecomputeFavouritesCommand extends Command
{
    protected $signature = 'prescriptions:recompute-favourites {--months=6 : trailing window}';

    protected $description = 'Rewrite doctor_favourites.rank from recent doctor_drug_usage (pinned rows first)';

    public function handle(DoctorLearningCache $cache): int
    {
        if (! Tenancy::check()) {
            $this->error('Run inside a tenant: tenants:run prescriptions:recompute-favourites');

            return self::FAILURE;
        }

        $since = Clock::today()->subMonths((int) $this->option('months'))->startOfMonth()->toDateString();
        $recent = [];

        foreach (DoctorDrugUsage::query()->where('period_month', '>=', $since)->get() as $u) {
            $key = $u->doctor_id.'|'.($u->icd10_code ?? '').'|'.$u->generic_id.'|'.($u->brand_id ?? 0).'|'.($u->custom_brand_id ?? 0);
            $recent[$key] = ($recent[$key] ?? 0) + $u->use_count;
        }

        $groups = DoctorFavourite::query()->get()->groupBy(fn (DoctorFavourite $f) => $f->doctor_id.'|'.($f->icd10_code ?? ''));
        $doctors = [];

        foreach ($groups as $rows) {
            $sorted = $rows->sortBy(fn (DoctorFavourite $f) => [$f->is_pinned ? 0 : 1, -($recent[$f->doctor_id.'|'.($f->icd10_code ?? '').'|'.$f->generic_id.'|'.($f->brand_id ?? 0).'|'.($f->custom_brand_id ?? 0)] ?? 0), -$f->use_count, $f->id])->values();

            foreach ($sorted as $i => $fav) {
                if ($fav->rank !== $i + 1) {
                    $fav->forceFill(['rank' => $i + 1])->save();
                }

                $doctors[$fav->doctor_id] = true;
            }
        }

        foreach (array_keys($doctors) as $doctorId) {
            $cache->forget((int) $doctorId);
        }

        $this->info('Ranked favourites for '.count($doctors).' doctor(s).');

        return self::SUCCESS;
    }
}
