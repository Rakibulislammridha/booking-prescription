<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Domain\Prescription\Data\DrugRef;
use App\Models\Tenant\DoctorFavourite;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The per-doctor quick-pick / boost caches (PRESCRIPTION.md §3.5, CONVENTIONS §15 key names):
 *   t:{tenantId}:doctor:{doctorId}:top50     → ordered list of favourite rows (TTL 7 d)
 *   t:{tenantId}:doctor:{doctorId}:usage     → presentation_key → {use_count, last_shorthand}
 *   t:{tenantId}:doctor:{doctorId}:fav:{icd} → list of presentation keys
 *   t:{tenantId}:doctor:{doctorId}:icd       → icd10_code → use_count
 * Stored through the cache repository (Redis in production, array in tests) under exactly those keys; misses are
 * rebuilt from doctor_favourites / doctor_drug_usage.
 */
final class DoctorLearningCache
{
    private const TTL = 7 * 86400;

    public function __construct(private readonly DrugRefResolver $drugs) {}

    public function key(int $doctorId, string $suffix): string
    {
        return 't:'.Tenancy::id().":doctor:{$doctorId}:{$suffix}";
    }

    /** @return list<array<string, mixed>> the doctor's global favourites (icd10_code NULL) ordered by rank, ≤ 50 */
    public function top50(int $doctorId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->store()->remember($this->key($doctorId, 'top50'), self::TTL, fn () => $this->buildTop50($doctorId));

        // A list cached before the row carried presentation facts would make every quick-picked line parse against
        // tablet defaults until the server echo corrected it (the ~400 ms flicker). Rebuild it rather than serve it:
        // the TTL is seven days and the key name is fixed by CONVENTIONS §15, so it cannot be versioned away.
        if ($rows !== [] && ! array_key_exists('form_code', (array) ($rows[0]['drug'] ?? []))) {
            $rows = $this->buildTop50($doctorId);
            $this->store()->put($this->key($doctorId, 'top50'), $rows, self::TTL);
        }

        return $rows;
    }

    /**
     * One favourite as the writer consumes it: the ids AND the presentation facts the client parser needs
     * (§2.15 ParseContext), so a one-click insert parses correctly on its FIRST render.
     *
     * @return array<string, mixed>
     */
    public function row(DoctorFavourite $f): array
    {
        return self::favouriteRow($f, $this->reference($f));
    }

    /** @return array<string, array{use_count: int, last_shorthand: string|null}> */
    public function usage(int $doctorId): array
    {
        return $this->store()->remember($this->key($doctorId, 'usage'), self::TTL, fn () => $this->buildUsage($doctorId));
    }

    /** @return list<string> presentation keys favoured for a diagnosis */
    public function favouritesFor(int $doctorId, string $icd): array
    {
        return $this->store()->remember($this->key($doctorId, 'fav:'.$icd), self::TTL, fn () => DoctorFavourite::query()
            ->where('doctor_id', $doctorId)->where('icd10_code', $icd)->get()->map(fn (DoctorFavourite $f) => $f->presentationKey())->values()->all());
    }

    /** @return array<string, int> */
    public function icdUsage(int $doctorId): array
    {
        return (array) $this->store()->get($this->key($doctorId, 'icd'), []);
    }

    public function bumpIcd(int $doctorId, string $icd): void
    {
        $usage = $this->icdUsage($doctorId);
        $usage[$icd] = ($usage[$icd] ?? 0) + 1;
        arsort($usage);
        $this->store()->put($this->key($doctorId, 'icd'), array_slice($usage, 0, 200, true), self::TTL);
    }

    /** @param  list<string>  $icdCodes */
    public function refresh(int $doctorId, array $icdCodes = []): void
    {
        $store = $this->store();
        $store->put($this->key($doctorId, 'top50'), $this->buildTop50($doctorId), self::TTL);
        $store->put($this->key($doctorId, 'usage'), $this->buildUsage($doctorId), self::TTL);

        foreach ($icdCodes as $icd) {
            $store->forget($this->key($doctorId, 'fav:'.$icd));
        }
    }

    public function forget(int $doctorId): void
    {
        foreach (['top50', 'usage', 'icd'] as $suffix) {
            $this->store()->forget($this->key($doctorId, $suffix));
        }
    }

    /** @return list<array<string, mixed>> */
    private function buildTop50(int $doctorId): array
    {
        return DoctorFavourite::query()->where('doctor_id', $doctorId)->whereNull('icd10_code')
            ->orderByDesc('is_pinned')->orderBy('rank')->orderByDesc('use_count')->limit(50)->get()
            ->map(fn (DoctorFavourite $f) => $this->row($f))->values()->all();
    }

    /** @return array<string, array{use_count: int, last_shorthand: string|null}> */
    private function buildUsage(int $doctorId): array
    {
        $usage = [];

        foreach (DoctorFavourite::query()->where('doctor_id', $doctorId)->whereNull('icd10_code')->get() as $f) {
            $usage[$f->presentationKey()] = ['use_count' => $f->use_count, 'last_shorthand' => isset($f->default_dose['shorthand']) ? (string) $f->default_dose['shorthand'] : null];
        }

        return $usage;
    }

    /**
     * TopDrug / favourite wire row (PRESCRIPTION.md §1.2, §3.5). `drug` is the writer's own DrugRef shape: the ids
     * plus the presentation facts `contextFor()` builds a ParseContext from — form_code, default_unit, strength_mg,
     * per_ml, pack_size/unit. Without them the client parses `2+0+2 7d` for a syrup as tablets for one round trip.
     * The keys are always present (null when the reference no longer resolves) so a cached row is recognisable.
     *
     * @return array<string, mixed>
     */
    public static function favouriteRow(DoctorFavourite $f, ?DrugRef $ref = null): array
    {
        $resolved = $ref !== null && $ref->resolved ? $ref : null;

        return [
            'id' => $f->id,
            'icd10_code' => $f->icd10_code,
            'drug' => [
                'kind' => $f->custom_brand_id !== null ? 'custom' : ($f->strength_id !== null ? 'presentation' : 'generic'),
                'generic_id' => $f->generic_id, 'brand_id' => $f->brand_id, 'custom_brand_id' => $f->custom_brand_id, 'strength_id' => $f->strength_id,
                'presentation_key' => $f->presentationKey(),
                'generic_name' => $resolved->genericName ?? $f->label, 'brand_name' => $resolved?->brandName,
                'strength' => $resolved?->strength, 'form' => $resolved?->form, 'form_code' => $resolved?->formCode,
                'route' => $resolved?->route, 'route_code' => $resolved?->routeCode,
                'pack_size' => $resolved?->packSize, 'pack_unit' => $resolved?->packUnit,
                'strength_mg' => $resolved?->strengthMg, 'per_ml' => $resolved?->perMl,
                'default_unit' => $resolved?->defaultUnit,
            ],
            'label' => $f->label,
            'default_dose' => $f->default_dose,
            'use_count' => $f->use_count,
            'is_pinned' => $f->is_pinned,
            'rank' => $f->rank,
            'last_used_at' => $f->last_used_at?->toIso8601String(),
        ];
    }

    private function reference(DoctorFavourite $f): DrugRef
    {
        return $this->drugs->resolve([
            'generic_id' => $f->generic_id, 'brand_id' => $f->brand_id,
            'custom_brand_id' => $f->custom_brand_id, 'strength_id' => $f->strength_id,
        ]);
    }

    private function store(): Repository
    {
        $name = config('prescription.cache_store');

        return Cache::store(is_string($name) && $name !== '' ? $name : null);
    }
}
