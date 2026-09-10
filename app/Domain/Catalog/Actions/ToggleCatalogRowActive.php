<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Search\CatalogSearchIndexer;
use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Models\Catalog\AllergyClass;
use App\Models\Catalog\Brand;
use App\Models\Catalog\CatalogModel;
use App\Models\Catalog\DrugInteraction;
use App\Models\Catalog\Generic;
use App\Models\Catalog\Icd10Code;
use App\Models\Catalog\Strength;
use Carbon\CarbonImmutable;

/**
 * The one structural thing the console may do to a catalogue row by hand: switch `is_active`. Nothing is ever
 * deleted (CATALOG.md C3) — a discontinued brand stays resolvable for every prescription that names it and
 * simply leaves autocomplete, which is what the search upsert afterwards makes true. Goes through
 * CatalogWriteContext (so it refuses anyone but a super admin or a console command) and bumps the cache version.
 *
 * @phpstan-type Kind 'generics'|'brands'|'strengths'|'icd10'|'interactions'|'allergy_classes'
 */
final class ToggleCatalogRowActive
{
    /** @var array<string, class-string<CatalogModel>> */
    public const MODELS = [
        'generics' => Generic::class,
        'brands' => Brand::class,
        'strengths' => Strength::class,
        'icd10' => Icd10Code::class,
        'interactions' => DrugInteraction::class,
        'allergy_classes' => AllergyClass::class,
    ];

    public function __construct(
        private readonly CatalogWriteContext $context,
        private readonly CatalogCache $cache,
    ) {}

    /**
     * @param  Kind  $kind
     * @return array{model: CatalogModel, before: array<string, mixed>, after: array<string, mixed>}
     */
    public function handle(string $kind, int $id, bool $active): array
    {
        $class = self::MODELS[$kind];

        /** @var array{model: CatalogModel, before: array<string, mixed>, after: array<string, mixed>} $result */
        $result = $this->context->run(function () use ($class, $id, $active, $kind): array {
            /** @var CatalogModel $row */
            $row = $class::query()->findOrFail($id);
            $before = ['is_active' => (bool) $row->getAttribute('is_active')];
            $attributes = ['is_active' => $active];

            if ($kind === 'brands') {
                $attributes['discontinued_at'] = $active ? null : CarbonImmutable::now();
            }

            $row->forceFill($attributes)->save();

            return ['model' => $row, 'before' => $before, 'after' => ['is_active' => $active]];
        }, 'console:toggle-active');

        $this->cache->bumpVersion();
        $this->reindex($kind, $id);

        return $result;
    }

    private function reindex(string $kind, int $id): void
    {
        if (config('scout.driver') !== 'meilisearch') {
            return;
        }

        $table = match ($kind) {
            'generics' => 'generics', 'brands' => 'brands', 'strengths' => 'strengths', 'icd10' => 'icd10_codes', default => null,
        };

        if ($table !== null) {
            app(CatalogSearchIndexer::class)->upsertChanged([$table => [$id]]);
        }
    }
}
