<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import;

use App\Domain\Catalog\Data\ImportReport;
use App\Domain\Catalog\Data\ImportRequest;
use App\Domain\Catalog\Data\ImportRow;
use App\Domain\Catalog\Enums\CatalogImportIssueKind;
use App\Domain\Catalog\Enums\CatalogVersionStatus;
use App\Domain\Catalog\Enums\ImportSource;
use App\Domain\Catalog\Enums\LactationRisk;
use App\Domain\Catalog\Events\CatalogVersionPublished;
use App\Domain\Catalog\Search\CatalogSearchIndexer;
use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Models\Catalog\AllergyClass;
use App\Models\Catalog\Brand;
use App\Models\Catalog\CatalogImportIssue;
use App\Models\Catalog\CatalogVersion;
use App\Models\Catalog\DosageForm;
use App\Models\Catalog\Generic;
use App\Models\Catalog\Route;
use Illuminate\Support\Str;

/**
 * The versioned, idempotent import pipeline (CATALOG.md §5). Every table is upserted by its canonical key, nothing is
 * deleted, --full deactivates what the file no longer lists, and the run is one catalog_admin transaction under a
 * catalog_versions row that goes draft → applied.
 */
final class CatalogImporter
{
    /** @var array<string, array{rows: int, inserted: int, updated: int, deactivated: int}> */
    private array $counts = [];

    /** @var array<string, list<int>> */
    private array $changed = [];

    /** @var array<string, int> */
    private array $issues = [];

    /** @var array<string, int> code → id */
    private array $routes = [];

    /** @var array<string, array{id: int, default_route_id: int|null, default_unit: string}> code → row */
    private array $forms = [];

    /** @var array<string, int> slug → id */
    private array $generics = [];

    /** @var array<string, string> generic slug → default presentations (seed) */
    private array $defaultPresentations = [];

    private int $versionId = 0;

    private Upserter $db;

    public function __construct(
        private readonly CatalogWriteContext $context,
        private readonly CsvReader $csv,
        private readonly StrengthLabelParser $parser,
        private readonly FormMapper $formMapper,
        private readonly GenericResolver $resolver,
        private readonly CatalogCache $cache,
    ) {}

    public function run(ImportRequest $request): ImportReport
    {
        $started = hrtime(true);
        $bundle = new Bundle($request->path);
        $checksum = $bundle->checksum();
        $version = $request->version ?? $request->source->value.'.'.now()->format('Ymd-His');

        $this->counts = $this->changed = $this->issues = [];
        $this->routes = $this->forms = $this->generics = $this->defaultPresentations = [];

        $existing = $this->context->run(fn () => CatalogVersion::query()->where('checksum_sha256', $checksum)->orderByDesc('id')->first());

        if ($existing !== null && ! $request->force) {
            return new ImportReport('already_imported', $existing->id, $existing->version, $checksum, durationMs: $this->elapsed($started));
        }

        try {
            $report = $this->context->run(function () use ($request, $bundle, $checksum, $version, $started): ImportReport {
                $this->db = Upserter::forCatalogAdmin();

                $row = CatalogVersion::query()->create([
                    'version' => $version,
                    'dgda_release_ref' => $request->releaseRef,
                    'status' => CatalogVersionStatus::Draft,
                    'checksum_sha256' => $checksum,
                    'notes' => $request->notes ?? ($request->source->value.' import of '.basename($bundle->path)),
                ]);
                $this->versionId = $row->id;

                $this->importRoutes($bundle);
                $this->importForms($bundle);
                $this->importGenerics($bundle, $request->source);
                $this->importProducts($bundle, $request);
                $this->importIcd10($bundle);
                $this->importInteractions($bundle);
                $this->importAllergyClasses($bundle);
                $this->importPregnancy($bundle);
                $this->importCautions($bundle, 'renal');
                $this->importCautions($bundle, 'hepatic');
                $this->importMaxDoses($bundle);
                $this->importDrugInformation($bundle);

                $this->issues = CatalogImportIssue::query()->where('catalog_version_id', $this->versionId)
                    ->selectRaw('kind, count(*) AS c')->groupBy('kind')->pluck('c', 'kind')->map(fn ($c) => (int) $c)->all();

                $report = new ImportReport('applied', $this->versionId, $version, $checksum, $this->counts, $this->changed, $this->issues, $this->elapsed($started));

                if ($request->dryRun) {
                    $report->status = 'dry_run';

                    throw new DryRunRollback($report);
                }

                CatalogVersion::query()->where('status', CatalogVersionStatus::Applied->value)->where('id', '<>', $this->versionId)
                    ->update(['status' => CatalogVersionStatus::Superseded->value, 'updated_at' => now()]);

                $row->forceFill([
                    'status' => CatalogVersionStatus::Applied,
                    'applied_at' => now(),
                    'applied_by' => $request->appliedBy,
                    'row_counts' => $this->counts,
                    'released_at' => $row->released_at ?? now(),
                ])->save();

                return $report;
            }, 'catalog:import');
        } catch (DryRunRollback $rollback) {
            return $rollback->report;
        }

        // After commit (CATALOG.md §5.7): new version prefix, optional reindex, notify.
        $this->cache->bumpVersion();

        if ($request->reindex && config('scout.driver') === 'meilisearch') {
            app(CatalogSearchIndexer::class)->rebuildAll();
        }

        event(new CatalogVersionPublished($report->versionId ?? 0, $report->version, $report->rowCounts, $report->issues));

        return $report;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Lookup tables

    private function importRoutes(Bundle $bundle): void
    {
        if ($bundle->has('routes')) {
            $rows = [];

            foreach ($this->csv->rows($bundle->file('routes')) as $r) {
                $rows[] = [
                    'code' => $r['code'], 'name' => $r['name'], 'name_bn' => self::nullable($r['name_bn'] ?? null),
                    'abbreviation' => $r['abbreviation'] !== '' ? $r['abbreviation'] : Str::upper($r['code']),
                    'is_systemic' => self::bool($r['is_systemic'] ?? '1', true), 'is_active' => true,
                ];
            }

            $this->record('routes', $this->db->upsert('routes', $rows, ['code'], ['name', 'name_bn', 'abbreviation', 'is_systemic', 'is_active'], $this->versionId), count($rows));
        }

        foreach (Route::query()->get(['id', 'code']) as $route) {
            $this->routes[$route->code->value] = $route->id;
        }
    }

    private function importForms(Bundle $bundle): void
    {
        if ($bundle->has('forms')) {
            $rows = [];

            foreach ($this->csv->rows($bundle->file('forms')) as $r) {
                $rows[] = [
                    'code' => $r['code'], 'name' => $r['name'], 'name_bn' => self::nullable($r['name_bn'] ?? null),
                    'abbreviation' => $r['abbreviation'], 'default_unit' => $r['default_unit'] !== '' ? $r['default_unit'] : 'unit',
                    'default_route_id' => $this->routes[$r['default_route_code'] ?? ''] ?? null,
                    'is_liquid' => self::bool($r['is_liquid'] ?? '0', false), 'pack_unit' => self::nullable($r['pack_unit'] ?? null), 'is_active' => true,
                ];
            }

            $this->record('dosage_forms', $this->db->upsert('dosage_forms', $rows, ['code'], ['name', 'name_bn', 'abbreviation', 'default_unit', 'default_route_id', 'is_liquid', 'pack_unit', 'is_active'], $this->versionId), count($rows));
        }

        foreach (DosageForm::query()->get(['id', 'code', 'default_route_id', 'default_unit']) as $form) {
            $this->forms[$form->code->value] = ['id' => $form->id, 'default_route_id' => $form->default_route_id, 'default_unit' => $form->default_unit];
        }
    }

    private function importGenerics(Bundle $bundle, ImportSource $source): void
    {
        if ($bundle->has('generics')) {
            $rows = [];
            $components = [];

            foreach ($this->csv->rows($bundle->file('generics')) as $r) {
                $slug = $r['slug'] !== '' ? $r['slug'] : Str::slug($r['name']);
                $rows[] = [
                    'slug' => $slug, 'name' => $r['name'], 'name_bn' => self::nullable($r['name_bn'] ?? null),
                    'atc_code' => self::nullable($r['atc_code'] ?? null), 'aliases' => self::list($r['aliases'] ?? ''),
                    'therapeutic_class' => self::nullable($r['therapeutic_class'] ?? null),
                    'is_controlled' => self::bool($r['is_controlled'] ?? '0', false),
                    'is_pediatric_weight_based' => self::bool($r['is_pediatric_weight_based'] ?? '0', false),
                    'needs_review' => false,
                    'is_active' => ! in_array(strtolower($r['status'] ?? ''), ['discontinued', 'inactive'], true),
                ];

                if (($r['components'] ?? '') !== '') {
                    $components[$slug] = $r['components'];
                }

                if (($r['default_presentations'] ?? '') !== '') {
                    $this->defaultPresentations[$slug] = $r['default_presentations'];
                }
            }

            $update = ['name', 'atc_code', 'is_controlled', 'is_pediatric_weight_based', 'needs_review', 'is_active'];

            if ($source !== ImportSource::Dgda) {
                $update = [...$update, 'name_bn', 'aliases', 'therapeutic_class'];             // never overwritten by DGDA rows
            }

            $this->record('generics', $this->db->upsert('generics', $rows, ['slug'], $update, $this->versionId), count($rows));
            $this->loadGenerics();

            // Second pass: components reference slugs, so ids exist only now.
            $componentRows = [];

            foreach ($components as $slug => $spec) {
                $list = [];

                foreach (explode('|', $spec) as $part) {
                    [$componentSlug, $mg] = array_pad(explode(':', trim($part), 2), 2, null);

                    if (isset($this->generics[$componentSlug])) {
                        $list[] = ['generic_id' => $this->generics[$componentSlug], 'mg' => $mg === null || $mg === '' ? null : (float) $mg];
                    }
                }

                if ($list !== []) {
                    $componentRows[] = ['slug' => $slug, 'name' => '', 'components' => $list];
                }
            }

            if ($componentRows !== []) {
                $this->db->upsert('generics', $componentRows, ['slug'], ['components'], $this->versionId);
            }
        } else {
            $this->loadGenerics();
        }
    }

    private function loadGenerics(): void
    {
        $this->generics = Generic::query()->pluck('id', 'slug')->map(fn ($id) => (int) $id)->all();
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Brands + strengths

    private function importProducts(Bundle $bundle, ImportRequest $request): void
    {
        if (! $bundle->hasProducts()) {
            return;
        }

        /** @var list<ImportRow> $rows */
        $rows = [];

        if ($bundle->has('brands')) {
            $mapper = new SeedRowMapper(fn (string $slug): ?string => $this->defaultPresentations[$slug] ?? null);

            foreach ($this->csv->rows($bundle->file('brands')) as $line => $record) {
                foreach ($mapper->map($record, $line) as $row) {
                    $rows[] = $row;
                }
            }
        }

        $dgda = new DgdaRowMapper;

        foreach ($bundle->productFiles() as $file) {
            foreach ($this->csv->rows($file) as $line => $record) {
                foreach ($dgda->map($record, $line) as $row) {
                    $rows[] = $row;
                }
            }
        }

        // 1. Resolve generics (seed rows by slug, DGDA rows through the resolver).
        $brandRows = [];        // key lower(name)|generic_id → row
        $brandMeta = [];        // key → [row objects] for strengths
        $issueRows = [];
        $genericCreated = false;

        foreach ($rows as $row) {
            $genericId = null;

            if ($row->genericSlug !== null && isset($this->generics[$row->genericSlug])) {
                $genericId = $this->generics[$row->genericSlug];
            } elseif ($request->source !== ImportSource::Seed || $row->genericSlug === null) {
                $genericId = $this->resolver->resolveOrCreate($row->genericText, $this->versionId, $row)->id;
                $genericCreated = true;
            }

            if ($genericId === null) {
                $this->issue(CatalogImportIssueKind::UnknownGeneric, $row, ['reason' => 'unknown generic slug '.$row->genericSlug]);

                continue;
            }

            $key = Str::lower($row->brand).'|'.$genericId;

            if (! isset($brandRows[$key])) {
                $brandRows[$key] = [
                    'generic_id' => $genericId,
                    'name' => $row->brand,
                    'slug' => '',                                                          // filled below (stable once created)
                    'manufacturer' => $row->manufacturer,
                    'dar_number' => $row->darNumber,
                    'popularity' => $row->popularity ?? 0,
                    'aliases' => $row->aliases,
                    'is_active' => ! $row->isDiscontinued(),
                    'discontinued_at' => $row->isDiscontinued() ? now()->toDateTimeString() : null,
                ];
            } elseif ($brandRows[$key]['manufacturer'] !== null && $row->manufacturer !== null
                && Str::lower($brandRows[$key]['manufacturer']) !== Str::lower($row->manufacturer)) {
                $issueRows[] = [$row, ['existing_manufacturer' => $brandRows[$key]['manufacturer']]];

                continue;
            }

            $brandMeta[$key][] = $row;
        }

        if ($genericCreated) {
            $this->loadGenerics();
        }

        foreach ($issueRows as [$row, $extra]) {
            $this->issue(CatalogImportIssueKind::DuplicateBrand, $row, $extra);
        }

        // 2. Slugs: keep existing ones; new brands get {brand}-{generic} with a -2 suffix on collision.
        $existingByKey = $this->db->idsByKey('brands', "lower(name) || '|' || generic_id", array_keys($brandRows));
        $slugById = [];

        if ($existingByKey !== []) {
            foreach (array_chunk(array_values($existingByKey), 1000) as $chunk) {
                foreach (Brand::query()->whereIn('id', $chunk)->get(['id', 'slug']) as $b) {
                    $slugById[$b->id] = $b->slug;
                }
            }
        }

        $genericSlugById = array_flip($this->generics);
        $taken = [];

        foreach ($brandRows as $key => &$row) {
            if (isset($existingByKey[$key])) {
                $row['slug'] = $slugById[$existingByKey[$key]] ?? Str::slug($row['name'].' '.($genericSlugById[$row['generic_id']] ?? ''));

                continue;
            }

            $base = Str::slug($row['name'].' '.($genericSlugById[$row['generic_id']] ?? ''));
            $slug = $base;
            $i = 2;

            while (isset($taken[$slug]) || Brand::query()->where('slug', $slug)->exists()) {
                $slug = $base.'-'.$i++;
            }

            $taken[$slug] = true;
            $row['slug'] = $slug;
        }
        unset($row);

        $update = ['manufacturer', 'dar_number', 'is_active', 'discontinued_at'];

        if ($request->source !== ImportSource::Dgda) {
            $update = [...$update, 'popularity', 'aliases'];                                 // never overwritten by DGDA rows
        }

        $this->record('brands', $this->db->upsert('brands', array_values($brandRows), ['lower(name)', 'generic_id'], $update, $this->versionId, [
            'discontinued_at' => 'CASE WHEN EXCLUDED."is_active" THEN NULL ELSE COALESCE(t."discontinued_at", EXCLUDED."discontinued_at") END',
        ]), count($brandRows));

        $brandIds = $this->db->idsByKey('brands', "lower(name) || '|' || generic_id", array_keys($brandRows));

        // 3. Strengths.
        $strengthRows = [];

        foreach ($brandMeta as $key => $items) {
            $brandId = $brandIds[$key] ?? null;

            if ($brandId === null) {
                continue;
            }

            foreach ($items as $row) {
                $formCode = $this->formMapper->code($row->formText);

                if ($formCode === null || ! isset($this->forms[$formCode])) {
                    $this->issue(CatalogImportIssueKind::UnknownForm, $row);

                    continue;
                }

                $parsed = $this->parser->tryParse($row->strengthLabel);

                if ($parsed === null) {
                    $this->issue(CatalogImportIssueKind::UnparsableStrength, $row);

                    continue;
                }

                $form = $this->forms[$formCode];
                $routeId = $row->routeText !== null ? ($this->routes[strtolower($row->routeText)] ?? null) : null;
                [$packValue, $packUnit] = $this->parser->parsePack($row->packSize, $form['default_unit']);
                $sKey = $brandId.'|'.$form['id'].'|'.$parsed->label;

                $strengthRows[$sKey] = [
                    'brand_id' => $brandId,
                    'generic_id' => $brandRows[$key]['generic_id'],
                    'dosage_form_id' => $form['id'],
                    'route_id' => $routeId ?? $form['default_route_id'],
                    'strength_label' => $parsed->label,
                    'strength_value' => $parsed->amountValue,
                    'strength_unit' => $parsed->strengthUnit(),
                    'per_volume_ml' => $parsed->perVolumeMl(),
                    'pack_size' => $row->packSize,
                    'unit_price_paisa' => $row->unitPricePaisa,
                    'strength_mg' => $parsed->strengthMg,
                    'per_ml' => $parsed->perMl,
                    'pack_size_value' => $packValue,
                    'pack_unit' => $packUnit,
                    'is_active' => ! $row->isDiscontinued(),
                ];
            }
        }

        $this->record('strengths', $this->db->upsert('strengths', array_values($strengthRows), ['brand_id', 'dosage_form_id', 'strength_label'],
            ['generic_id', 'route_id', 'strength_value', 'strength_unit', 'per_volume_ml', 'pack_size', 'unit_price_paisa', 'strength_mg', 'per_ml', 'pack_size_value', 'pack_unit', 'is_active'],
            $this->versionId), count($strengthRows));

        // 4. --full: this file is the complete list → everything it does not mention is discontinued (never deleted).
        if ($request->full) {
            $strengthIds = $this->db->idsByKey('strengths', "brand_id || '|' || dosage_form_id || '|' || strength_label", array_keys($strengthRows));
            $this->deactivated('strengths', $this->db->deactivateExcept('strengths', array_values($strengthIds), $this->versionId));
            $this->deactivated('brands', $this->db->deactivateExcept('brands', array_values($brandIds), $this->versionId, stampDiscontinued: true));

            if ($bundle->has('generics')) {
                $this->deactivated('generics', $this->db->deactivateExcept('generics', array_values($this->generics), $this->versionId));
            }
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Reference data

    private function importIcd10(Bundle $bundle): void
    {
        if (! $bundle->has('icd10')) {
            return;
        }

        $rows = [];
        $parents = [];

        foreach ($this->csv->rows($bundle->file('icd10')) as $r) {
            $rows[] = [
                'code' => $r['code'], 'title' => $r['title'], 'title_bn' => self::nullable($r['title_bn'] ?? null),
                'chapter' => self::nullable($r['chapter'] ?? null), 'block' => self::nullable($r['block'] ?? null),
                'aliases' => self::list($r['aliases'] ?? ''), 'is_billable' => self::bool($r['is_billable'] ?? '1', true),
                'is_active' => ! in_array(strtolower($r['status'] ?? ''), ['discontinued', 'inactive'], true),
            ];

            if (($r['parent_code'] ?? '') !== '') {
                $parents[$r['code']] = $r['parent_code'];
            }
        }

        $result = $this->db->upsert('icd10_codes', $rows, ['code'], ['title', 'title_bn', 'chapter', 'block', 'aliases', 'is_billable', 'is_active'], $this->versionId);
        $this->record('icd10_codes', $result, count($rows));

        // parent_code is a self-FK: link after every code of the bundle exists; unknown parents stay NULL.
        $known = $this->db->idsByKey('icd10_codes', 'code', array_values($parents));
        $parentRows = [];

        foreach ($parents as $code => $parent) {
            if (isset($known[$parent])) {
                $parentRows[] = ['code' => $code, 'title' => '', 'parent_code' => $parent];
            }
        }

        if ($parentRows !== []) {
            $linked = $this->db->upsert('icd10_codes', $parentRows, ['code'], ['parent_code'], $this->versionId);
            $this->counts['icd10_codes']['updated'] += $linked['updated'];
            $this->changed['icd10_codes'] = array_values(array_unique([...$this->changed['icd10_codes'], ...$linked['ids']]));
        }
    }

    private function importInteractions(Bundle $bundle): void
    {
        if (! $bundle->has('interactions')) {
            return;
        }

        $rows = [];

        foreach ($this->csv->rows($bundle->file('interactions')) as $line => $r) {
            $a = $this->generics[$r['generic_a']] ?? null;
            $b = $this->generics[$r['generic_b']] ?? null;

            if ($a === null || $b === null || $a === $b) {
                $this->issue(CatalogImportIssueKind::UnknownGeneric, new ImportRow($line, null, '', $r['generic_a'].' + '.$r['generic_b'], null, null), ['file' => 'interactions']);

                continue;
            }

            $rows[min($a, $b).':'.max($a, $b)] = [
                'generic_a_id' => min($a, $b), 'generic_b_id' => max($a, $b), 'severity' => strtolower($r['severity']),
                'mechanism' => self::nullable($r['mechanism'] ?? null), 'effect' => $r['effect'], 'management' => self::nullable($r['management'] ?? null),
                'evidence_level' => self::nullable($r['evidence_level'] ?? null), 'source' => self::nullable($r['source'] ?? null), 'is_active' => true,
            ];
        }

        $this->record('drug_interactions', $this->db->upsert('drug_interactions', array_values($rows), ['generic_a_id', 'generic_b_id'],
            ['severity', 'mechanism', 'effect', 'management', 'evidence_level', 'source', 'is_active'], $this->versionId), count($rows));
    }

    private function importAllergyClasses(Bundle $bundle): void
    {
        if ($bundle->has('allergy_classes')) {
            $rows = [];
            $cross = [];

            foreach ($this->csv->rows($bundle->file('allergy_classes')) as $r) {
                $rows[] = ['slug' => $r['slug'], 'name' => $r['name'], 'description' => self::nullable($r['description'] ?? null), 'is_active' => true];

                if (($r['cross_reacts_with'] ?? '') !== '') {
                    $cross[$r['slug']] = $r['cross_reacts_with'];
                }
            }

            $this->record('allergy_classes', $this->db->upsert('allergy_classes', $rows, ['slug'], ['name', 'description', 'is_active'], $this->versionId), count($rows));

            $ids = $this->db->idsByKey('allergy_classes', 'slug', array_map(fn ($r) => $r['slug'], $rows));
            $crossRows = [];

            foreach ($cross as $slug => $spec) {
                $list = [];

                foreach (explode('|', $spec) as $part) {
                    [$target, $pct] = array_pad(explode(':', trim($part), 2), 2, '10');

                    if (isset($ids[$target])) {
                        $list[] = ['allergy_class_id' => $ids[$target], 'probability_pct' => (int) $pct];
                    }
                }

                $crossRows[] = ['slug' => $slug, 'name' => '', 'cross_reacts_with' => $list];
            }

            if ($crossRows !== []) {
                $linked = $this->db->upsert('allergy_classes', $crossRows, ['slug'], ['cross_reacts_with'], $this->versionId);
                $this->counts['allergy_classes']['updated'] += $linked['updated'];
            }
        }

        if ($bundle->has('allergy_class_generics')) {
            $ids = AllergyClass::query()->pluck('id', 'slug')->map(fn ($v) => (int) $v)->all();
            $rows = [];

            foreach ($this->csv->rows($bundle->file('allergy_class_generics')) as $line => $r) {
                $class = $ids[$r['class_slug']] ?? null;
                $generic = $this->generics[$r['generic_slug']] ?? null;

                if ($class === null || $generic === null) {
                    $this->issue(CatalogImportIssueKind::UnknownGeneric, new ImportRow($line, null, '', $r['generic_slug'], null, null), ['file' => 'allergy_class_generics', 'class' => $r['class_slug']]);

                    continue;
                }

                $rows[$class.':'.$generic] = ['allergy_class_id' => $class, 'generic_id' => $generic, 'is_active' => true];
            }

            $this->record('allergy_class_generics', $this->db->upsert('allergy_class_generics', array_values($rows), ['allergy_class_id', 'generic_id'], ['is_active'], $this->versionId, updatedAt: false), count($rows));
        }
    }

    private function importPregnancy(Bundle $bundle): void
    {
        if (! $bundle->has('pregnancy')) {
            return;
        }

        $rows = [];

        foreach ($this->csv->rows($bundle->file('pregnancy')) as $line => $r) {
            $generic = $this->generics[$r['generic_slug']] ?? null;

            if ($generic === null) {
                $this->issue(CatalogImportIssueKind::UnknownGeneric, new ImportRow($line, null, '', $r['generic_slug'], null, null), ['file' => 'pregnancy']);

                continue;
            }

            $trimester = ($r['trimester'] ?? '') === '' ? null : (int) $r['trimester'];
            $rows[$generic.':'.($trimester ?? 0)] = [
                'generic_id' => $generic, 'trimester' => $trimester, 'category' => strtoupper($r['category']),
                'lactation' => self::lactation($r['lactation'] ?? ''), 'notes' => self::nullable($r['notes'] ?? null), 'is_active' => true,
            ];
        }

        $this->record('pregnancy_categories', $this->db->upsert('pregnancy_categories', array_values($rows), ['generic_id', 'COALESCE(trimester, 0)'],
            ['category', 'lactation', 'notes', 'is_active'], $this->versionId), count($rows));
    }

    private function importCautions(Bundle $bundle, string $kind): void
    {
        if (! $bundle->has($kind)) {
            return;
        }

        $table = $kind.'_cautions';
        $column = $kind === 'renal' ? 'egfr_below' : 'child_pugh_class';
        $conflict = $kind === 'renal' ? 'COALESCE(egfr_below, -1)' : "COALESCE(child_pugh_class, '-')";
        $rows = [];

        foreach ($this->csv->rows($bundle->file($kind)) as $line => $r) {
            $generic = $this->generics[$r['generic_slug']] ?? null;

            if ($generic === null) {
                $this->issue(CatalogImportIssueKind::UnknownGeneric, new ImportRow($line, null, '', $r['generic_slug'], null, null), ['file' => $kind]);

                continue;
            }

            $threshold = ($r[$column] ?? '') === '' ? null : ($kind === 'renal' ? (int) $r[$column] : strtoupper($r[$column]));
            $rows[$generic.':'.($threshold ?? '-')] = [
                'generic_id' => $generic, $column => $threshold, 'level' => strtolower($r['level']), 'advice' => $r['advice'], 'is_active' => true,
            ];
        }

        $this->record($table, $this->db->upsert($table, array_values($rows), ['generic_id', $conflict], ['level', 'advice', 'is_active'], $this->versionId), count($rows));
    }

    private function importMaxDoses(Bundle $bundle): void
    {
        if (! $bundle->has('max_doses')) {
            return;
        }

        $rows = [];

        foreach ($this->csv->rows($bundle->file('max_doses')) as $line => $r) {
            $generic = $this->generics[$r['generic_slug']] ?? null;

            if ($generic === null) {
                $this->issue(CatalogImportIssueKind::UnknownGeneric, new ImportRow($line, null, '', $r['generic_slug'], null, null), ['file' => 'max_doses']);

                continue;
            }

            $route = ($r['route_code'] ?? '') === '' ? null : ($this->routes[$r['route_code']] ?? null);
            $population = ($r['population'] ?? '') === '' ? 'adult' : strtolower($r['population']);
            $minAge = ($r['min_age_months'] ?? '') === '' ? null : (int) $r['min_age_months'];
            $rows[$generic.':'.($route ?? 0).':'.$population.':'.($minAge ?? -1)] = [
                'generic_id' => $generic, 'route_id' => $route, 'population' => $population,
                'max_mg_per_day' => self::number($r['max_mg_per_day'] ?? ''), 'max_mg_per_kg_per_day' => self::number($r['max_mg_per_kg_per_day'] ?? ''),
                'max_mg_per_dose' => self::number($r['max_mg_per_dose'] ?? ''), 'min_age_months' => $minAge,
                'max_age_months' => ($r['max_age_months'] ?? '') === '' ? null : (int) $r['max_age_months'],
                'notes' => self::nullable($r['notes'] ?? null), 'is_active' => true,
            ];
        }

        $this->record('max_daily_doses', $this->db->upsert('max_daily_doses', array_values($rows),
            ['generic_id', 'COALESCE(route_id, 0)', 'population', 'COALESCE(min_age_months, -1)'],
            ['max_mg_per_day', 'max_mg_per_kg_per_day', 'max_mg_per_dose', 'max_age_months', 'notes', 'is_active'], $this->versionId), count($rows));
    }

    private function importDrugInformation(Bundle $bundle): void
    {
        if (! $bundle->has('drug_information')) {
            return;
        }

        $rows = [];

        foreach ($this->csv->rows($bundle->file('drug_information')) as $line => $r) {
            $generic = $this->generics[$r['generic_slug']] ?? null;

            if ($generic === null) {
                $this->issue(CatalogImportIssueKind::UnknownGeneric, new ImportRow($line, null, '', $r['generic_slug'], null, null), ['file' => 'drug_information']);

                continue;
            }

            $rows[$generic] = [
                'generic_id' => $generic, 'public_slug' => ($r['public_slug'] ?? '') !== '' ? $r['public_slug'] : $r['generic_slug'],
                'indications' => self::nullable($r['indications'] ?? null), 'indications_bn' => self::nullable($r['indications_bn'] ?? null),
                'side_effects' => self::nullable($r['side_effects'] ?? null), 'side_effects_bn' => self::nullable($r['side_effects_bn'] ?? null),
                'contraindications' => self::nullable($r['contraindications'] ?? null), 'precautions' => self::nullable($r['precautions'] ?? null),
                'patient_advice_bn' => self::nullable($r['patient_advice_bn'] ?? null),
                'published_at' => self::bool($r['published'] ?? '1', true) ? now()->toDateTimeString() : null, 'is_active' => true,
            ];
        }

        $this->record('drug_information', $this->db->upsert('drug_information', array_values($rows), ['generic_id'],
            ['public_slug', 'indications', 'indications_bn', 'side_effects', 'side_effects_bn', 'contraindications', 'precautions', 'patient_advice_bn', 'published_at', 'is_active'],
            $this->versionId, [
                'public_slug' => 'CASE WHEN t."published_at" IS NULL THEN EXCLUDED."public_slug" ELSE t."public_slug" END',   // never changed once published
                'published_at' => 'COALESCE(t."published_at", EXCLUDED."published_at")',
            ]), count($rows));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Helpers

    /** @param  array{inserted: int, updated: int, ids: list<int>}  $result */
    private function record(string $table, array $result, int $rows): void
    {
        $this->counts[$table] = ['rows' => $rows, 'inserted' => $result['inserted'], 'updated' => $result['updated'], 'deactivated' => $this->counts[$table]['deactivated'] ?? 0];
        $this->changed[$table] = array_values(array_unique([...($this->changed[$table] ?? []), ...$result['ids']]));
    }

    /** @param  list<int>  $ids */
    private function deactivated(string $table, array $ids): void
    {
        $this->counts[$table] ??= ['rows' => 0, 'inserted' => 0, 'updated' => 0, 'deactivated' => 0];
        $this->counts[$table]['deactivated'] += count($ids);
        $this->changed[$table] = array_values(array_unique([...($this->changed[$table] ?? []), ...$ids]));
    }

    /** @param  array<string, mixed>  $extra */
    private function issue(CatalogImportIssueKind $kind, ImportRow $row, array $extra = []): void
    {
        CatalogImportIssue::query()->create([
            'catalog_version_id' => $this->versionId,
            'kind' => $kind,
            'source_row' => $row->sourceRow,
            'payload' => $row->toPayload() + $extra,
        ]);
    }

    private function elapsed(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }

    private static function nullable(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    private static function bool(string $value, bool $default): bool
    {
        $v = strtolower(trim($value));

        return $v === '' ? $default : in_array($v, ['1', 'true', 'yes', 'y', 't'], true);
    }

    private static function number(string $value): ?float
    {
        return trim($value) === '' ? null : (float) $value;
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode('|', $value)), fn ($v) => $v !== ''));
    }

    /** Hale L1–L2 → safe, L3 → caution, L4–L5 → avoid (CATALOG.md §3.1); explicit values pass through. */
    private static function lactation(string $value): string
    {
        $v = strtolower(trim($value));

        return match (true) {
            LactationRisk::tryFrom($v) !== null => $v,
            in_array($v, ['l1', 'l2'], true) => LactationRisk::Safe->value,
            $v === 'l3' => LactationRisk::Caution->value,
            in_array($v, ['l4', 'l5'], true) => LactationRisk::Avoid->value,
            default => LactationRisk::Unknown->value,
        };
    }
}
