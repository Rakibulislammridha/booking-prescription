<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

/**
 * The public "click here for more information" data behind GET /drug/{slug} (PRESCRIPTION.md §7.8 — the page is the
 * Prescription module's; this is the read accessor). Cached 1 h on top of the version-keyed CatalogCache.
 */
final class DrugInformationLookup
{
    public function __construct(private readonly CatalogCache $cache) {}

    /**
     * @return array{slug: string, generic_name: string, generic_name_bn: string|null, published: bool, indications: string|null,
     *     indications_bn: string|null, side_effects: string|null, side_effects_bn: string|null, contraindications: string|null,
     *     precautions: string|null, patient_advice_bn: string|null, catalog_version: string|null}|null
     */
    public function bySlug(string $slug): ?array
    {
        $row = $this->cache->drugInformation($slug);

        if ($row === null) {
            return null;
        }

        $published = $row['published_at'] !== null;

        return [
            'slug' => (string) $row['public_slug'],
            'generic_name' => (string) $row['generic_name'],
            'generic_name_bn' => $row['generic_name_bn'] ?? null,
            'published' => $published,
            'indications' => $published ? $row['indications'] : null,
            'indications_bn' => $published ? $row['indications_bn'] : null,
            'side_effects' => $published ? $row['side_effects'] : null,
            'side_effects_bn' => $published ? $row['side_effects_bn'] : null,
            'contraindications' => $published ? $row['contraindications'] : null,
            'precautions' => $published ? $row['precautions'] : null,
            'patient_advice_bn' => $published ? $row['patient_advice_bn'] : null,
            'catalog_version' => $this->cache->currentVersion(),
        ];
    }

    /** The slug to snapshot into prescription_items.info_url_slug at issue time. */
    public function slugForGeneric(int $genericId): ?string
    {
        $row = $this->cache->drugInformationForGeneric($genericId);

        return $row === null ? null : (string) $row['public_slug'];
    }
}
