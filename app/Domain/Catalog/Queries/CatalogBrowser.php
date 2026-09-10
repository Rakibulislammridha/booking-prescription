<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Queries;

use App\Domain\Catalog\Search\CatalogSearchIndexer;
use App\Models\Catalog\AllergyClass;
use App\Models\Catalog\Brand;
use App\Models\Catalog\CatalogVersion;
use App\Models\Catalog\DrugInteraction;
use App\Models\Catalog\Generic;
use App\Models\Catalog\Icd10Code;
use App\Models\Catalog\MaxDailyDose;
use App\Models\Catalog\Strength;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Meilisearch\Client;
use Throwable;

/**
 * The console's read-only view of the shared catalogue (BRIEF §3.2): six tabs, server-side search, an
 * active/inactive filter, and the drawer detail. Search goes to Meilisearch when it is the driver and reachable
 * — the same `catalog_drugs` / `catalog_icd10` indexes doctors type into, so an operator sees what a doctor
 * sees — and falls back to Postgres ILIKE otherwise. Meilisearch only ever supplies candidate ids; the rows,
 * the active filter and the pagination are the database's, so the list is exact even while an index rebuild
 * is in flight.
 *
 * @phpstan-type Page array{rows: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int, per_page: int}, engine: string}
 */
final class CatalogBrowser
{
    public const TABS = ['generics', 'brands', 'strengths', 'icd10', 'interactions', 'allergy_classes'];

    private const CANDIDATES = 200;

    public function __construct(private readonly Client $client, private readonly CatalogSearchIndexer $indexer) {}

    /** @return Page */
    public function list(string $tab, string $q, string $active, int $page, int $perPage = 50): array
    {
        $q = trim($q);
        $query = $this->baseQuery($tab);
        $engine = 'database';

        if ($active === 'active' || $active === 'inactive') {
            $query->where(self::alias($tab).'.is_active', $active === 'active');
        }

        if ($q !== '') {
            $ids = $this->candidates($tab, $q);

            if ($ids !== null) {
                $engine = 'meilisearch';
                $query->whereIn(self::alias($tab).'.id', $ids === [] ? [0] : $ids);

                if ($ids !== []) {
                    $query->orderByRaw('array_position(ARRAY['.implode(',', array_map('intval', $ids)).']::bigint[], '.self::alias($tab).'.id)');
                }
            } else {
                $this->databaseSearch($tab, $query, $q);
            }
        }

        $this->defaultOrder($tab, $query);
        $total = (clone $query)->getCountForPagination();
        $rows = $query->forPage($page, $perPage)->get()->map(fn (object $r): array => $this->present($tab, $r))->values()->all();

        return [
            'rows' => $rows,
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total, 'per_page' => $perPage],
            'engine' => $engine,
        ];
    }

    /** @return array<string, mixed>|null */
    public function detail(string $tab, int $id): ?array
    {
        return match ($tab) {
            'generics' => $this->generic($id),
            'brands' => $this->brand($id),
            'strengths' => $this->strength($id),
            'icd10' => $this->icd10($id),
            'interactions' => $this->interaction($id),
            'allergy_classes' => $this->allergyClass($id),
            default => null,
        };
    }

    /** @return array<string, int> tab → row count, for the tab strip */
    public function counts(): array
    {
        $c = DB::connection('catalog');

        return [
            'generics' => (int) $c->table('generics')->count(),
            'brands' => (int) $c->table('brands')->count(),
            'strengths' => (int) $c->table('strengths')->count(),
            'icd10' => (int) $c->table('icd10_codes')->count(),
            'interactions' => (int) $c->table('drug_interactions')->count(),
            'allergy_classes' => (int) $c->table('allergy_classes')->count(),
        ];
    }

    // ── list queries ───────────────────────────────────────────────────────────────────────────────────────

    private function baseQuery(string $tab): Builder
    {
        $c = DB::connection('catalog');

        return match ($tab) {
            'generics' => $c->table('generics AS g')
                ->leftJoin('drug_information AS di', 'di.generic_id', '=', 'g.id')
                ->selectRaw('g.*, di.public_slug AS info_slug, di.published_at AS info_published_at, (SELECT count(*) FROM brands b WHERE b.generic_id = g.id) AS brands_count'),
            'brands' => $c->table('brands AS b')->join('generics AS g', 'g.id', '=', 'b.generic_id')
                ->selectRaw('b.*, g.name AS generic_name, (SELECT count(*) FROM strengths s WHERE s.brand_id = b.id) AS strengths_count'),
            'strengths' => $c->table('strengths AS s')->join('brands AS b', 'b.id', '=', 's.brand_id')->join('generics AS g', 'g.id', '=', 's.generic_id')
                ->join('dosage_forms AS f', 'f.id', '=', 's.dosage_form_id')->leftJoin('routes AS r', 'r.id', '=', 's.route_id')
                ->selectRaw('s.*, b.name AS brand_name, g.name AS generic_name, f.name AS form_name, f.code AS form_code, r.name AS route_name, r.code AS route_code'),
            'icd10' => $c->table('icd10_codes AS i')->selectRaw('i.*'),
            'interactions' => $c->table('drug_interactions AS x')->join('generics AS ga', 'ga.id', '=', 'x.generic_a_id')->join('generics AS gb', 'gb.id', '=', 'x.generic_b_id')
                ->selectRaw('x.*, ga.name AS generic_a_name, gb.name AS generic_b_name'),
            'allergy_classes' => $c->table('allergy_classes AS a')
                ->selectRaw('a.*, (SELECT count(*) FROM allergy_class_generics acg WHERE acg.allergy_class_id = a.id) AS members_count'),
            default => throw new \InvalidArgumentException("Unknown catalog tab {$tab}"),
        };
    }

    private static function alias(string $tab): string
    {
        return match ($tab) {
            'generics' => 'g', 'brands' => 'b', 'strengths' => 's', 'icd10' => 'i', 'interactions' => 'x', 'allergy_classes' => 'a',
            default => throw new \InvalidArgumentException("Unknown catalog tab {$tab}"),
        };
    }

    private function defaultOrder(string $tab, Builder $query): void
    {
        match ($tab) {
            'generics' => $query->orderBy('g.name'),
            'brands' => $query->orderByDesc('b.popularity')->orderBy('b.name'),
            'strengths' => $query->orderBy('b.name')->orderBy('s.strength_label'),
            'icd10' => $query->orderBy('i.code'),
            'interactions' => $query->orderBy('ga.name')->orderBy('gb.name'),
            'allergy_classes' => $query->orderBy('a.name'),
            default => null,
        };
    }

    /** ILIKE over the columns a human would type: names, slugs/codes, aliases in both scripts. */
    private function databaseSearch(string $tab, Builder $query, string $q): void
    {
        $needle = mb_strtolower($q);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle).'%';
        $aliasHas = fn (string $column): string => "EXISTS (SELECT 1 FROM jsonb_array_elements_text({$column}) al WHERE lower(al) LIKE ?)";

        match ($tab) {
            'generics' => $query->where(fn (Builder $w) => $w->whereRaw('lower(g.name) LIKE ?', [$like])->orWhereRaw('lower(g.slug) LIKE ?', [$like])
                ->orWhereRaw('lower(coalesce(g.name_bn, \'\')) LIKE ?', [$like])->orWhereRaw($aliasHas('g.aliases'), [$like])
                ->orWhereRaw('lower(coalesce(g.therapeutic_class, \'\')) LIKE ?', [$like])),
            'brands' => $query->where(fn (Builder $w) => $w->whereRaw('lower(b.name) LIKE ?', [$like])->orWhereRaw('lower(b.slug) LIKE ?', [$like])
                ->orWhereRaw('lower(g.name) LIKE ?', [$like])->orWhereRaw('lower(coalesce(b.manufacturer, \'\')) LIKE ?', [$like])->orWhereRaw($aliasHas('b.aliases'), [$like])),
            'strengths' => $query->where(fn (Builder $w) => $w->whereRaw('lower(b.name) LIKE ?', [$like])->orWhereRaw('lower(g.name) LIKE ?', [$like])
                ->orWhereRaw('lower(s.strength_label) LIKE ?', [$like])),
            'icd10' => $query->where(fn (Builder $w) => $w->whereRaw('lower(i.code) LIKE ?', [$like])->orWhereRaw('lower(i.title) LIKE ?', [$like])
                ->orWhereRaw('lower(coalesce(i.title_bn, \'\')) LIKE ?', [$like])->orWhereRaw($aliasHas('i.aliases'), [$like])),
            'interactions' => $query->where(fn (Builder $w) => $w->whereRaw('lower(ga.name) LIKE ?', [$like])->orWhereRaw('lower(gb.name) LIKE ?', [$like])
                ->orWhereRaw('lower(x.severity) LIKE ?', [$like])),
            'allergy_classes' => $query->where(fn (Builder $w) => $w->whereRaw('lower(a.name) LIKE ?', [$like])->orWhereRaw('lower(a.slug) LIKE ?', [$like])),
            default => null,
        };
    }

    /**
     * Candidate row ids from Meilisearch in ranking order; null when the engine is not in play (not the driver,
     * unreachable, or a tab the indexes do not cover) so the caller falls back to ILIKE.
     *
     * @return list<int>|null
     */
    private function candidates(string $tab, string $q): ?array
    {
        if (config('scout.driver') !== 'meilisearch' || ! in_array($tab, ['generics', 'brands', 'strengths', 'icd10'], true)) {
            return null;
        }

        try {
            if ($tab === 'icd10') {
                $hits = $this->client->index($this->indexer->uid(CatalogSearchIndexer::ICD10))
                    ->rawSearch($q, ['limit' => self::CANDIDATES, 'attributesToRetrieve' => ['code']])['hits'] ?? [];
                $codes = array_values(array_unique(array_map(fn (array $h) => (string) $h['code'], $hits)));

                if ($codes === []) {
                    return [];
                }

                $byCode = DB::connection('catalog')->table('icd10_codes')->whereIn('code', $codes)->pluck('id', 'code');

                return array_values(array_filter(array_map(fn (string $code) => isset($byCode[$code]) ? (int) $byCode[$code] : null, $codes)));
            }

            [$filter, $field, $distinct] = match ($tab) {
                'generics' => ['doc_type = generic', 'generic_id', null],
                'brands' => ['doc_type = presentation', 'brand_id', 'brand_id'],
                default => ['doc_type = presentation', 'strength_id', null],
            };

            $params = ['limit' => self::CANDIDATES, 'filter' => $filter, 'attributesToRetrieve' => [$field]];

            if ($distinct !== null) {
                $params['distinct'] = $distinct;
            }

            $hits = $this->client->index($this->indexer->uid(CatalogSearchIndexer::DRUGS))->rawSearch($q, $params)['hits'] ?? [];
            $ids = [];

            foreach ($hits as $hit) {
                if (isset($hit[$field])) {
                    $ids[(int) $hit[$field]] = true;
                }
            }

            return array_keys($ids);
        } catch (Throwable) {
            return null;                                              // the index is down: Postgres answers instead
        }
    }

    // ── rows ───────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function present(string $tab, object $r): array
    {
        return match ($tab) {
            'generics' => [
                'id' => (int) $r->id, 'name' => $r->name, 'name_bn' => $r->name_bn, 'slug' => $r->slug, 'atc_code' => $r->atc_code,
                'therapeutic_class' => $r->therapeutic_class, 'aliases' => self::json($r->aliases), 'is_controlled' => (bool) $r->is_controlled,
                'is_pediatric_weight_based' => (bool) $r->is_pediatric_weight_based, 'needs_review' => (bool) $r->needs_review,
                'brands_count' => (int) $r->brands_count, 'info_slug' => $r->info_slug, 'info_published' => $r->info_published_at !== null,
                'is_active' => (bool) $r->is_active, 'catalog_version_id' => $r->catalog_version_id === null ? null : (int) $r->catalog_version_id,
            ],
            'brands' => [
                'id' => (int) $r->id, 'name' => $r->name, 'slug' => $r->slug, 'manufacturer' => $r->manufacturer, 'dar_number' => $r->dar_number,
                'popularity' => (int) $r->popularity, 'aliases' => self::json($r->aliases), 'generic' => ['id' => (int) $r->generic_id, 'name' => $r->generic_name],
                'strengths_count' => (int) $r->strengths_count, 'discontinued_at' => $r->discontinued_at,
                'is_active' => (bool) $r->is_active, 'catalog_version_id' => $r->catalog_version_id === null ? null : (int) $r->catalog_version_id,
            ],
            'strengths' => [
                'id' => (int) $r->id, 'brand' => ['id' => (int) $r->brand_id, 'name' => $r->brand_name], 'generic' => ['id' => (int) $r->generic_id, 'name' => $r->generic_name],
                'form' => ['name' => $r->form_name, 'code' => $r->form_code], 'route' => $r->route_name === null ? null : ['name' => $r->route_name, 'code' => $r->route_code],
                'strength_label' => $r->strength_label, 'pack_size' => $r->pack_size, 'strength_mg' => self::num($r->strength_mg), 'per_ml' => self::num($r->per_ml),
                'unit_price_paisa' => $r->unit_price_paisa === null ? null : (int) $r->unit_price_paisa,
                'is_active' => (bool) $r->is_active, 'catalog_version_id' => $r->catalog_version_id === null ? null : (int) $r->catalog_version_id,
            ],
            'icd10' => [
                'id' => (int) $r->id, 'code' => $r->code, 'title' => $r->title, 'title_bn' => $r->title_bn, 'chapter' => $r->chapter, 'block' => $r->block,
                'parent_code' => $r->parent_code, 'aliases' => self::json($r->aliases), 'is_billable' => (bool) $r->is_billable,
                'is_active' => (bool) $r->is_active, 'catalog_version_id' => $r->catalog_version_id === null ? null : (int) $r->catalog_version_id,
            ],
            'interactions' => [
                'id' => (int) $r->id, 'generic_a' => ['id' => (int) $r->generic_a_id, 'name' => $r->generic_a_name], 'generic_b' => ['id' => (int) $r->generic_b_id, 'name' => $r->generic_b_name],
                'severity' => $r->severity, 'effect' => $r->effect, 'mechanism' => $r->mechanism, 'management' => $r->management, 'evidence_level' => $r->evidence_level, 'source' => $r->source,
                'is_active' => (bool) $r->is_active, 'catalog_version_id' => $r->catalog_version_id === null ? null : (int) $r->catalog_version_id,
            ],
            'allergy_classes' => [
                'id' => (int) $r->id, 'name' => $r->name, 'slug' => $r->slug, 'description' => $r->description, 'members_count' => (int) $r->members_count,
                'cross_reacts_with' => self::json($r->cross_reacts_with),
                'is_active' => (bool) $r->is_active, 'catalog_version_id' => $r->catalog_version_id === null ? null : (int) $r->catalog_version_id,
            ],
            default => [],
        };
    }

    // ── detail ─────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function generic(int $id): ?array
    {
        $g = Generic::query()->with(['information', 'allergyClasses', 'pregnancyCategories', 'renalCautions', 'hepaticCautions', 'maxDailyDoses.route', 'brands' => fn ($q) => $q->withCount('strengths')->orderByDesc('popularity')])->find($id);

        if ($g === null) {
            return null;
        }

        $interactions = DrugInteraction::query()->with(['genericA:id,name', 'genericB:id,name'])
            ->where(fn ($w) => $w->where('generic_a_id', $g->id)->orWhere('generic_b_id', $g->id))->orderBy('severity')->get();
        $components = [];

        foreach ((array) $g->components as $component) {
            $componentId = (int) $component['generic_id'];
            $components[] = ['generic_id' => $componentId, 'mg' => $component['mg'], 'name' => Generic::query()->whereKey($componentId)->value('name')];
        }

        return [
            'kind' => 'generics',
            'id' => $g->id, 'name' => $g->name, 'name_bn' => $g->name_bn, 'slug' => $g->slug, 'atc_code' => $g->atc_code, 'therapeutic_class' => $g->therapeutic_class,
            'aliases' => (array) $g->aliases, 'is_controlled' => $g->is_controlled, 'is_pediatric_weight_based' => $g->is_pediatric_weight_based,
            'needs_review' => $g->needs_review, 'is_active' => $g->is_active, 'components' => $components,
            'version' => $this->version($g->catalog_version_id),
            'brands' => $g->brands->map(fn (Brand $b) => ['id' => $b->id, 'name' => $b->name, 'manufacturer' => $b->manufacturer, 'popularity' => $b->popularity, 'strengths_count' => (int) $b->getAttribute('strengths_count'), 'is_active' => $b->is_active])->values()->all(),
            'information' => $g->information === null ? null : [
                'id' => $g->information->id, 'public_slug' => $g->information->public_slug, 'published_at' => $g->information->published_at?->toIso8601String(),
                'indications' => $g->information->indications, 'indications_bn' => $g->information->indications_bn,
                'side_effects' => $g->information->side_effects, 'side_effects_bn' => $g->information->side_effects_bn,
                'contraindications' => $g->information->contraindications, 'precautions' => $g->information->precautions, 'patient_advice_bn' => $g->information->patient_advice_bn,
            ],
            'allergy_classes' => $g->allergyClasses->map(fn (AllergyClass $a) => ['id' => $a->id, 'name' => $a->name, 'slug' => $a->slug])->values()->all(),
            'pregnancy' => $g->pregnancyCategories->map(fn ($p) => ['trimester' => $p->trimester, 'category' => $p->category, 'lactation' => $p->lactation, 'notes' => $p->notes])->values()->all(),
            'renal' => $g->renalCautions->map(fn ($c) => ['egfr_below' => $c->egfr_below, 'level' => $c->level, 'advice' => $c->advice])->values()->all(),
            'hepatic' => $g->hepaticCautions->map(fn ($c) => ['child_pugh_class' => $c->child_pugh_class, 'level' => $c->level, 'advice' => $c->advice])->values()->all(),
            'max_doses' => $g->maxDailyDoses->map(fn (MaxDailyDose $d) => [
                'route' => $d->route?->name, 'population' => $d->population, 'max_mg_per_day' => self::num($d->max_mg_per_day), 'max_mg_per_kg_per_day' => self::num($d->max_mg_per_kg_per_day),
                'max_mg_per_dose' => self::num($d->max_mg_per_dose), 'min_age_months' => $d->min_age_months, 'max_age_months' => $d->max_age_months, 'notes' => $d->notes,
            ])->values()->all(),
            'interactions' => $interactions->map(fn (DrugInteraction $x) => [
                'id' => $x->id, 'with' => $x->generic_a_id === $g->id ? ['id' => $x->generic_b_id, 'name' => $x->genericB?->name] : ['id' => $x->generic_a_id, 'name' => $x->genericA?->name],
                'severity' => $x->severity->value, 'effect' => $x->effect, 'management' => $x->management, 'is_active' => $x->is_active,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function brand(int $id): ?array
    {
        $b = Brand::query()->with(['generic:id,name,slug,is_active', 'strengths.dosageForm', 'strengths.route'])->find($id);

        if ($b === null) {
            return null;
        }

        return [
            'kind' => 'brands',
            'id' => $b->id, 'name' => $b->name, 'slug' => $b->slug, 'manufacturer' => $b->manufacturer, 'dar_number' => $b->dar_number, 'popularity' => $b->popularity,
            'aliases' => (array) $b->aliases, 'is_active' => $b->is_active, 'discontinued_at' => $b->discontinued_at?->toIso8601String(),
            'generic' => $b->generic === null ? null : ['id' => $b->generic->id, 'name' => $b->generic->name, 'slug' => $b->generic->slug, 'is_active' => $b->generic->is_active],
            'version' => $this->version($b->catalog_version_id),
            'strengths' => $b->strengths->map(fn (Strength $s) => [
                'id' => $s->id, 'strength_label' => $s->strength_label, 'form' => $s->dosageForm?->name, 'form_code' => $s->dosageForm?->code->value, 'route' => $s->route?->name,
                'pack_size' => $s->pack_size, 'strength_mg' => self::num($s->strength_mg), 'per_ml' => self::num($s->per_ml), 'unit_price_paisa' => $s->unit_price_paisa, 'is_active' => $s->is_active,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function strength(int $id): ?array
    {
        $s = Strength::query()->with(['brand:id,name,manufacturer,is_active', 'generic:id,name,is_active', 'dosageForm', 'route'])->find($id);

        if ($s === null) {
            return null;
        }

        return [
            'kind' => 'strengths',
            'id' => $s->id, 'strength_label' => $s->strength_label, 'strength_value' => self::num($s->strength_value), 'strength_unit' => $s->strength_unit,
            'per_volume_ml' => self::num($s->per_volume_ml), 'strength_mg' => self::num($s->strength_mg), 'per_ml' => self::num($s->per_ml),
            'pack_size' => $s->pack_size, 'pack_size_value' => self::num($s->pack_size_value), 'pack_unit' => $s->pack_unit, 'unit_price_paisa' => $s->unit_price_paisa,
            'is_active' => $s->is_active, 'version' => $this->version($s->catalog_version_id),
            'brand' => $s->brand === null ? null : ['id' => $s->brand->id, 'name' => $s->brand->name, 'manufacturer' => $s->brand->manufacturer, 'is_active' => $s->brand->is_active],
            'generic' => $s->generic === null ? null : ['id' => $s->generic->id, 'name' => $s->generic->name, 'is_active' => $s->generic->is_active],
            'form' => $s->dosageForm === null ? null : ['name' => $s->dosageForm->name, 'code' => $s->dosageForm->code->value, 'default_unit' => $s->dosageForm->default_unit],
            'route' => $s->route === null ? null : ['name' => $s->route->name, 'code' => $s->route->code->value],
        ];
    }

    /** @return array<string, mixed>|null */
    private function icd10(int $id): ?array
    {
        $i = Icd10Code::query()->with(['parent:id,code,title', 'children' => fn ($q) => $q->orderBy('code')])->find($id);

        if ($i === null) {
            return null;
        }

        return [
            'kind' => 'icd10',
            'id' => $i->id, 'code' => $i->code, 'title' => $i->title, 'title_bn' => $i->title_bn, 'chapter' => $i->chapter, 'block' => $i->block,
            'aliases' => (array) $i->aliases, 'is_billable' => $i->is_billable, 'is_active' => $i->is_active, 'version' => $this->version($i->catalog_version_id),
            'parent' => $i->parent === null ? null : ['id' => $i->parent->id, 'code' => $i->parent->code, 'title' => $i->parent->title],
            'children' => $i->children->map(fn (Icd10Code $c) => ['id' => $c->id, 'code' => $c->code, 'title' => $c->title, 'is_active' => $c->is_active])->values()->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function interaction(int $id): ?array
    {
        $x = DrugInteraction::query()->with(['genericA:id,name', 'genericB:id,name'])->find($id);

        if ($x === null) {
            return null;
        }

        return [
            'kind' => 'interactions',
            'id' => $x->id, 'generic_a' => ['id' => $x->generic_a_id, 'name' => $x->genericA?->name], 'generic_b' => ['id' => $x->generic_b_id, 'name' => $x->genericB?->name],
            'severity' => $x->severity->value, 'mechanism' => $x->mechanism, 'effect' => $x->effect, 'management' => $x->management,
            'evidence_level' => $x->evidence_level?->value, 'source' => $x->source, 'is_active' => $x->is_active, 'version' => $this->version($x->catalog_version_id),
        ];
    }

    /** @return array<string, mixed>|null */
    private function allergyClass(int $id): ?array
    {
        $a = AllergyClass::query()->with(['generics' => fn ($q) => $q->orderBy('name')])->find($id);

        if ($a === null) {
            return null;
        }

        $cross = [];

        foreach ((array) $a->cross_reacts_with as $entry) {
            $targetId = (int) $entry['allergy_class_id'];
            $cross[] = ['allergy_class_id' => $targetId, 'name' => AllergyClass::query()->whereKey($targetId)->value('name'), 'probability_pct' => $entry['probability_pct']];
        }

        return [
            'kind' => 'allergy_classes',
            'id' => $a->id, 'name' => $a->name, 'slug' => $a->slug, 'description' => $a->description, 'is_active' => $a->is_active, 'version' => $this->version($a->catalog_version_id),
            'cross_reacts_with' => $cross,
            'members' => $a->generics->map(fn (Generic $g) => ['id' => $g->id, 'name' => $g->name, 'is_active' => $g->is_active])->values()->all(),
        ];
    }

    /** @return array{id: int, version: string, applied_at: string|null}|null */
    private function version(mixed $versionId): ?array
    {
        if ($versionId === null) {
            return null;
        }

        $v = CatalogVersion::query()->find((int) $versionId);

        return $v === null ? null : ['id' => $v->id, 'version' => $v->version, 'applied_at' => $v->applied_at?->toIso8601String()];
    }

    /** @return list<mixed> */
    private static function json(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private static function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
