<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\DrugInformationSlugLocked;
use App\Domain\Catalog\Search\CatalogSearchIndexer;
use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Models\Catalog\DrugInformation;
use App\Models\Catalog\Generic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The patient-facing information behind `/drug/{public_slug}` (SCHEMA §4 `drug_information`), edited from the
 * console. Text is free to change at any time — a printed prescription links to the slug, not to a snapshot of
 * the page. The SLUG is not: once published it is snapshotted into `prescription_items.info_url_slug` and is never
 * changed (CATALOG.md §2), so a rename is refused with a domain error rather than silently breaking old links.
 * Publishing stamps `published_at`; unpublishing clears it and the page falls back to the generic placeholder.
 */
final class UpdateDrugInformation
{
    public const FIELDS = ['indications', 'indications_bn', 'side_effects', 'side_effects_bn', 'contraindications', 'precautions', 'patient_advice_bn'];

    public function __construct(
        private readonly CatalogWriteContext $context,
        private readonly CatalogCache $cache,
    ) {}

    /**
     * @param  array<string, string|null>  $text  keys of self::FIELDS
     * @return array{model: DrugInformation, before: array<string, mixed>, after: array<string, mixed>}
     */
    public function handle(int $genericId, array $text, ?string $slug, bool $published): array
    {
        /** @var array{model: DrugInformation, before: array<string, mixed>, after: array<string, mixed>} $result */
        $result = $this->context->run(function () use ($genericId, $text, $slug, $published): array {
            $generic = Generic::query()->findOrFail($genericId);
            $row = DrugInformation::query()->where('generic_id', $generic->id)->first();
            $attributes = array_intersect_key($text, array_flip(self::FIELDS));

            foreach ($attributes as $key => $value) {
                $attributes[$key] = $value === null || trim($value) === '' ? null : trim($value);
            }

            $wanted = $slug !== null && trim($slug) !== '' ? Str::slug(trim($slug)) : null;

            if ($row === null) {
                $row = new DrugInformation;
                $row->forceFill(['generic_id' => $generic->id, 'public_slug' => $wanted ?? $generic->slug, 'is_active' => true, 'catalog_version_id' => $generic->catalog_version_id]);
                $before = [];
            } else {
                $before = array_intersect_key($row->getAttributes(), array_flip([...self::FIELDS, 'public_slug', 'published_at']));

                if ($wanted !== null && $wanted !== $row->public_slug) {
                    if ($row->published_at !== null) {
                        throw new DrugInformationSlugLocked($row->public_slug);
                    }

                    $row->forceFill(['public_slug' => $wanted]);
                }
            }

            $row->forceFill($attributes);

            if ($published && $row->published_at === null) {
                $row->forceFill(['published_at' => CarbonImmutable::now()]);
            } elseif (! $published && $row->published_at !== null) {
                $row->forceFill(['published_at' => null]);
            }

            $row->save();

            $after = array_intersect_key($row->getAttributes(), array_flip([...self::FIELDS, 'public_slug', 'published_at']));

            return ['model' => $row, 'before' => self::changed($before, $after), 'after' => self::changed($after, $before)];
        }, 'console:drug-information');

        $this->cache->bumpVersion();

        if (config('scout.driver') === 'meilisearch') {
            app(CatalogSearchIndexer::class)->upsertChanged(['generics' => [$genericId]]);   // documents carry info_slug
        }

        return $result;
    }

    /**
     * Only the attributes that differ between the two sides, so the audit row is a diff.
     *
     * @param  array<string, mixed>  $side
     * @param  array<string, mixed>  $other
     * @return array<string, mixed>
     */
    private static function changed(array $side, array $other): array
    {
        $out = [];

        foreach ($side as $key => $value) {
            if (! array_key_exists($key, $other) || $other[$key] != $value) {
                $out[$key] = $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value)->toIso8601String() : $value;
            }
        }

        return $out;
    }
}
