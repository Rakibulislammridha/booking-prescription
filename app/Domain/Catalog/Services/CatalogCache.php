<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\CatalogVersionStatus;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read-through cache in front of the `catalog` connection, keyed by catalog version so an import invalidates
 * everything at once (PRESCRIPTION.md §5.6): catalog:{ver}:generic:{id}, …:interaction:{a}:{b} ("none" cached too),
 * …:maxdose:{generic} … TTL 24 h; catalog:current_version TTL 60 s. Negative lookups are cached as "none".
 * This is the read API the safety pipeline, CatalogIdExists and the search documents use. Resolve it from the
 * container: `app(CatalogCache::class)->dosageForm($id)` (PRESCRIPTION.md §3.2's static spelling cannot coexist with
 * same-named instance methods).
 */
final class CatalogCache
{
    public const TABLES = ['generics', 'brands', 'strengths', 'dosage_forms', 'routes', 'allergy_classes', 'icd10_codes'];

    private const NONE = '__none__';

    /** @var array<string, mixed> per-request memo (flushed with the singleton / by bumpVersion) */
    private array $memo = [];

    /** @var array<string, array<int|string, array<string, mixed>>>|null table → id → row; set by fake() */
    private ?array $fake = null;

    private ?string $version = null;

    public function __construct(private readonly ?Repository $store = null) {}

    /** @param  array<string, array<int|string, array<string, mixed>>>  $tables  e.g. ['generics' => [17 => [...]]] */
    public static function fake(array $tables = []): self
    {
        $instance = new self(Cache::store('array'));
        $instance->fake = $tables;
        $instance->version = 'fake';
        app()->instance(self::class, $instance);

        return $instance;
    }

    // ------------------------------------------------------------------------------------------------ versions

    /** The version string of the latest applied catalog_versions row (PRESCRIPTION.md §5.5 `catalog_version`). */
    public function currentVersion(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $cached = $this->store()->get('catalog:current_version');

        if (is_string($cached) && $cached !== '') {
            return $this->version = $cached;
        }

        $row = DB::connection('catalog')->table('catalog_versions')->where('status', CatalogVersionStatus::Applied->value)
            ->orderByDesc('applied_at')->orderByDesc('id')->first(['id', 'version']);

        $version = $row === null ? 'v0' : (string) $row->version;
        $this->store()->put('catalog:current_version', $version, (int) config('catalog.cache.version_ttl', 60));

        return $this->version = $version;
    }

    /** @return array<string, mixed>|null the latest applied catalog_versions row */
    public function currentVersionRow(): ?array
    {
        $row = DB::connection('catalog')->table('catalog_versions')->where('status', CatalogVersionStatus::Applied->value)
            ->orderByDesc('applied_at')->orderByDesc('id')->first();

        return $row === null ? null : (array) $row;
    }

    /** After an import commits: forget the version pointer so every key gets a new prefix (old keys expire). */
    public function bumpVersion(): void
    {
        $this->store()->forget('catalog:current_version');
        $this->version = null;
        $this->memo = [];
    }

    // ------------------------------------------------------------------------------------------------ rows

    /**
     * One row by primary key (icd10_codes by `code`). Null when absent; inactive rows are returned with is_active=false.
     *
     * @return array<string, mixed>|null
     */
    public function row(string $table, int|string $id): ?array
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException("CatalogCache does not serve table {$table}");
        }

        $rows = $this->rows($table, [$id]);

        return $rows[$id] ?? null;
    }

    /**
     * Batch lookup: MGET-style read with one WHERE IN for the misses.
     *
     * @param  list<int|string>  $ids
     * @return array<int|string, array<string, mixed>>
     */
    public function rows(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id !== '')));

        if ($ids === []) {
            return [];
        }

        if ($this->fake !== null) {
            return array_intersect_key($this->fake[$table] ?? [], array_flip($ids));
        }

        $entity = $this->entity($table);
        $keys = [];

        foreach ($ids as $id) {
            $keys[$id] = $this->key("{$entity}:{$id}");
        }

        $found = [];
        $missing = [];

        foreach ($keys as $id => $key) {
            if (array_key_exists($key, $this->memo)) {
                $value = $this->memo[$key];
            } else {
                $value = $this->store()->get($key);
                $this->memo[$key] = $value;
            }

            if ($value === null) {
                $missing[] = $id;
            } elseif ($value !== self::NONE) {
                $found[$id] = $value;
            }
        }

        if ($missing !== []) {
            $keyColumn = $table === 'icd10_codes' ? 'code' : 'id';
            $rows = DB::connection('catalog')->table($table)->whereIn($keyColumn, $missing)->get();
            $byId = [];

            foreach ($rows as $row) {
                $byId[$row->{$keyColumn}] = $this->normalise($table, (array) $row);
            }

            foreach ($missing as $id) {
                $value = $byId[$id] ?? self::NONE;
                $this->store()->put($keys[$id], $value, $this->ttl());
                $this->memo[$keys[$id]] = $value;

                if ($value !== self::NONE) {
                    $found[$id] = $value;
                }
            }
        }

        return $found;
    }

    /** @return array<string, mixed>|null */
    public function generic(int $id): ?array
    {
        return $this->row('generics', $id);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function generics(array $ids): array
    {
        return $this->rows('generics', $ids);
    }

    /** @return array<string, mixed>|null */
    public function brand(int $id): ?array
    {
        return $this->row('brands', $id);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function brands(array $ids): array
    {
        return $this->rows('brands', $ids);
    }

    /** @return array<string, mixed>|null */
    public function strength(int $id): ?array
    {
        return $this->row('strengths', $id);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function strengths(array $ids): array
    {
        return $this->rows('strengths', $ids);
    }

    /** @return array<string, mixed>|null code, name, abbreviation, default_unit, is_liquid, pack_unit, default_route_id */
    public function dosageForm(int $id): ?array
    {
        return $this->row('dosage_forms', $id);
    }

    /** @return array<string, mixed>|null */
    public function route(int $id): ?array
    {
        return $this->row('routes', $id);
    }

    /** @return array<string, mixed>|null */
    public function allergyClass(int $id): ?array
    {
        return $this->row('allergy_classes', $id);
    }

    /** @return array<string, mixed>|null */
    public function icd10(string $code): ?array
    {
        return $this->row('icd10_codes', $code);
    }

    // ------------------------------------------------------------------------------------------------ safety data

    /**
     * drug_interactions row for an unordered pair, or null. Negative results are cached.
     *
     * @return array<string, mixed>|null
     */
    public function interaction(int $a, int $b): ?array
    {
        return $this->interactions([[$a, $b]])[min($a, $b).':'.max($a, $b)] ?? null;
    }

    /**
     * Batch: [[a, b], …] → "min:max" → row (missing keys = no interaction).
     *
     * @param  list<array{0: int, 1: int}>  $pairs
     * @return array<string, array<string, mixed>>
     */
    public function interactions(array $pairs): array
    {
        $keys = [];

        foreach ($pairs as [$a, $b]) {
            if ($a !== $b) {
                $keys[min($a, $b).':'.max($a, $b)] = true;
            }
        }

        if ($keys === []) {
            return [];
        }

        if ($this->fake !== null) {
            return array_intersect_key($this->fake['drug_interactions'] ?? [], $keys);
        }

        $found = [];
        $missing = [];

        foreach (array_keys($keys) as $pair) {
            $key = $this->key("interaction:{$pair}");
            $value = $this->memo[$key] ?? $this->store()->get($key);
            $this->memo[$key] = $value;

            if ($value === null) {
                $missing[] = $pair;
            } elseif ($value !== self::NONE) {
                $found[$pair] = $value;
            }
        }

        if ($missing !== []) {
            $query = DB::connection('catalog')->table('drug_interactions')->where('is_active', true);
            $query->where(function ($q) use ($missing): void {
                foreach ($missing as $pair) {
                    [$a, $b] = explode(':', $pair);
                    $q->orWhere(fn ($w) => $w->where('generic_a_id', (int) $a)->where('generic_b_id', (int) $b));
                }
            });

            $byPair = [];

            foreach ($query->get() as $row) {
                $byPair[$row->generic_a_id.':'.$row->generic_b_id] = (array) $row;
            }

            foreach ($missing as $pair) {
                $value = $byPair[$pair] ?? self::NONE;
                $this->store()->put($this->key("interaction:{$pair}"), $value, $this->ttl());
                $this->memo[$this->key("interaction:{$pair}")] = $value;

                if ($value !== self::NONE) {
                    $found[$pair] = $value;
                }
            }
        }

        return $found;
    }

    /** @return list<int> generic ids that belong to the allergy class */
    public function allergyClassGenerics(int $classId): array
    {
        return $this->remember("allergy_class:{$classId}:generics", 'allergy_class_generics', fn () => DB::connection('catalog')->table('allergy_class_generics')
            ->where('allergy_class_id', $classId)->where('is_active', true)->pluck('generic_id')->map(fn ($v) => (int) $v)->all(), $classId);
    }

    /** @return list<int> allergy class ids the generic belongs to */
    public function genericAllergyClasses(int $genericId): array
    {
        return $this->remember("generic:{$genericId}:allergy_classes", 'generic_allergy_classes', fn () => DB::connection('catalog')->table('allergy_class_generics')
            ->where('generic_id', $genericId)->where('is_active', true)->pluck('allergy_class_id')->map(fn ($v) => (int) $v)->all(), $genericId);
    }

    /** @return list<array<string, mixed>> max_daily_doses rows for the generic (all populations / routes) */
    public function maxDoses(int $genericId): array
    {
        return $this->remember("maxdose:{$genericId}", 'max_daily_doses', fn () => $this->activeRows('max_daily_doses', $genericId), $genericId);
    }

    /** @return list<array<string, mixed>> pregnancy_categories rows (trimester NULL = all) */
    public function pregnancy(int $genericId): array
    {
        return $this->remember("pregnancy:{$genericId}", 'pregnancy_categories', fn () => $this->activeRows('pregnancy_categories', $genericId), $genericId);
    }

    /** @return list<array<string, mixed>> renal_cautions rows, most specific threshold first */
    public function renal(int $genericId): array
    {
        return $this->remember("renal:{$genericId}", 'renal_cautions', fn () => $this->activeRows('renal_cautions', $genericId, 'egfr_below'), $genericId);
    }

    /** @return list<array<string, mixed>> hepatic_cautions rows */
    public function hepatic(int $genericId): array
    {
        return $this->remember("hepatic:{$genericId}", 'hepatic_cautions', fn () => $this->activeRows('hepatic_cautions', $genericId, 'child_pugh_class'), $genericId);
    }

    /**
     * drug_information row by public slug (PRESCRIPTION.md §7.8), joined with the generic's name.
     *
     * @return array<string, mixed>|null
     */
    public function drugInformation(string $publicSlug): ?array
    {
        return $this->remember("drug_info:{$publicSlug}", 'drug_information', function () use ($publicSlug): ?array {
            $row = DB::connection('catalog')->table('drug_information')
                ->join('generics', 'generics.id', '=', 'drug_information.generic_id')
                ->where('drug_information.public_slug', $publicSlug)->where('drug_information.is_active', true)
                ->first(['drug_information.*', 'generics.name AS generic_name', 'generics.name_bn AS generic_name_bn', 'generics.slug AS generic_slug']);

            return $row === null ? null : (array) $row;
        }, $publicSlug);
    }

    /** @return array<string, mixed>|null drug_information row for a generic (info_slug on search documents) */
    public function drugInformationForGeneric(int $genericId): ?array
    {
        return $this->remember("generic:{$genericId}:drug_info", 'drug_information_by_generic', function () use ($genericId): ?array {
            $row = DB::connection('catalog')->table('drug_information')->where('generic_id', $genericId)->where('is_active', true)->first();

            return $row === null ? null : (array) $row;
        }, $genericId);
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** @return list<array<string, mixed>> */
    private function activeRows(string $table, int $genericId, ?string $orderDesc = null): array
    {
        $query = DB::connection('catalog')->table($table)->where('generic_id', $genericId)->where('is_active', true);

        if ($orderDesc !== null) {
            $query->orderByRaw("{$orderDesc} DESC NULLS LAST");
        }

        return array_map(fn ($r) => (array) $r, $query->orderBy('id')->get()->all());
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $loader
     * @return T
     */
    private function remember(string $suffix, string $fakeTable, \Closure $loader, int|string $fakeKey): mixed
    {
        if ($this->fake !== null) {
            return $this->fake[$fakeTable][$fakeKey] ?? (str_starts_with($suffix, 'drug_info') || str_ends_with($suffix, ':drug_info') ? null : []);
        }

        $key = $this->key($suffix);

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key] === self::NONE ? null : $this->memo[$key];
        }

        $value = $this->store()->get($key);

        if ($value === null) {
            $value = $loader() ?? self::NONE;
            $this->store()->put($key, $value, $this->ttl());
        }

        $this->memo[$key] = $value;

        return $value === self::NONE ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalise(string $table, array $row): array
    {
        foreach (['aliases', 'components', 'cross_reacts_with'] as $json) {
            if (array_key_exists($json, $row) && is_string($row[$json])) {
                $row[$json] = json_decode($row[$json], true);
            }
        }

        foreach ($row as $k => $v) {
            if (in_array($k, ['is_active', 'is_controlled', 'is_pediatric_weight_based', 'is_liquid', 'is_systemic', 'is_billable', 'needs_review'], true)) {
                $row[$k] = (bool) $v;
            } elseif (str_ends_with($k, '_id') || $k === 'id' || $k === 'popularity') {
                $row[$k] = $v === null ? null : (int) $v;
            }
        }

        if (is_string($row['code'] ?? null) === false && isset($row['code'])) {
            $row['code'] = (string) $row['code'];
        }

        return $row;
    }

    private function entity(string $table): string
    {
        return match ($table) {
            'generics' => 'generic', 'brands' => 'brand', 'strengths' => 'strength', 'dosage_forms' => 'dosage_form',
            'routes' => 'route', 'allergy_classes' => 'allergy_class', 'icd10_codes' => 'icd10', default => $table,
        };
    }

    private function key(string $suffix): string
    {
        return 'catalog:'.$this->currentVersion().':'.$suffix;
    }

    private function ttl(): int
    {
        return (int) config('catalog.cache.row_ttl', 86400);
    }

    private function store(): Repository
    {
        if ($this->store !== null) {
            return $this->store;
        }

        $name = config('catalog.cache.store');

        return Cache::store(is_string($name) && $name !== '' ? $name : null);
    }
}
