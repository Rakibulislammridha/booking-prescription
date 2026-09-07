<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Contracts\PatientAccessResolver;
use App\Models\Tenant\Patient;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Patient lookup for the desk, the writer and the offline cache warm-up. Meilisearch (t{tenantId}_patients,
 * SCHEMA §5.6) when SCOUT_DRIVER=meilisearch; otherwise a Postgres fallback — mobile / patient code / public id
 * exact-ish, name via the trigram index (ILIKE). Results are constrained by PatientAccessResolver when a staff
 * user is given.
 */
final class PatientSearch
{
    public const DEFAULT_LIMIT = 20;

    public function __construct(private readonly PatientAccessResolver $access) {}

    /** @return Collection<int, Patient> */
    public function search(string $query, int $limit = self::DEFAULT_LIMIT, ?User $for = null): Collection
    {
        $query = trim($query);
        $limit = max(1, min(100, $limit));

        if ($query === '') {
            return $this->recent($limit, $for);
        }

        return $this->usesMeilisearch() ? $this->viaMeilisearch($query, $limit, $for) : $this->viaDatabase($query, $limit, $for);
    }

    /**
     * Most recently seen / registered patients (offline cache warm-up).
     *
     * @return Collection<int, Patient>
     */
    public function recent(int $limit = 50, ?User $for = null): Collection
    {
        $q = $this->base($for)->orderByRaw('last_visit_at desc nulls last')->orderByDesc('id')->limit(max(1, min(200, $limit)));

        return $q->get();
    }

    /**
     * Everyone on one mobile, owner first (the booking-flow household list).
     *
     * @return Collection<int, Patient>
     */
    public function household(string $mobile, ?User $for = null): Collection
    {
        $e164 = MobileNumber::tryNormalize($mobile);

        if ($e164 === null) {
            return new Collection;
        }

        return $this->base($for)->household($e164)->get();
    }

    public function usesMeilisearch(): bool
    {
        return config('scout.driver') === 'meilisearch';
    }

    /** @return Collection<int, Patient> */
    private function viaMeilisearch(string $query, int $limit, ?User $for): Collection
    {
        $term = MobileNumber::tryNormalize($query);
        $term = $term !== null ? MobileNumber::toLocal($term) : $query;

        /** @var Collection<int, Patient> $models */
        $models = Patient::search($term)->take($limit)->get();

        return $for === null ? $models : $models->filter(fn (Patient $p) => $this->access->canAccess($for, $p))->values();
    }

    /** @return Collection<int, Patient> */
    private function viaDatabase(string $query, int $limit, ?User $for): Collection
    {
        $q = $this->base($for);
        $ascii = MobileNumber::tryNormalize($query);
        $digits = preg_replace('/\D/', '', str_replace(['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'], ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $query)) ?? '';

        if ($ascii !== null) {
            $q->where('mobile', $ascii);
        } elseif (preg_match('/^p-?\d{1,6}$/i', $query) === 1) {
            $q->where('patient_code', 'P-'.str_pad(preg_replace('/\D/', '', $query) ?? '', 6, '0', STR_PAD_LEFT));
        } elseif (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $query) === 1) {
            $q->where('public_id', strtoupper($query));
        } elseif ($digits !== '' && strlen($digits) >= 4 && strlen($digits) >= strlen($query) - 3) {
            $q->where('mobile', 'like', '%'.ltrim(preg_replace('/^(00)?88/', '', $digits) ?? '', '+').'%');
        } else {
            $q->where('name_normalized', 'ilike', '%'.mb_strtolower(trim($query)).'%');
        }

        return $q->orderByRaw('last_visit_at desc nulls last')->orderByDesc('id')->limit($limit)->get();
    }

    /** @return Builder<Patient> */
    private function base(?User $for): Builder
    {
        $q = Patient::query();

        if ($for !== null) {
            $this->access->constrain($for, $q);
        }

        return $q;
    }
}
