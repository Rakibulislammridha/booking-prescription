<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import;

use App\Domain\Catalog\Data\ImportRow;
use App\Domain\Catalog\Enums\CatalogImportIssueKind;
use App\Models\Catalog\CatalogImportIssue;
use App\Models\Catalog\Generic;
use Illuminate\Support\Str;

/**
 * Free text → Generic (CATALOG.md §5.3): lower-case, strip pharmacopoeia tags and salts, match slug, then aliases,
 * then trigram ≥ threshold. Unknown text creates a needs_review generic plus an unknown_generic issue — a brand is
 * never imported without a generic. Runs inside CatalogWriteContext (reads go through catalog_admin too).
 */
final class GenericResolver
{
    private const SALTS = [
        'sodium', 'potassium', 'calcium', 'magnesium', 'hydrochloride', 'hcl', 'sulfate', 'sulphate', 'maleate', 'mesylate', 'mesilate',
        'succinate', 'tartrate', 'bitartrate', 'citrate', 'acetate', 'phosphate', 'dipropionate', 'propionate', 'xinafoate', 'trihydrate',
        'monohydrate', 'dihydrate', 'anhydrous', 'besylate', 'besilate', 'fumarate', 'bromide', 'butylbromide', 'hydrobromide', 'nitrate',
        'stearate', 'valerate', 'pivalate', 'disodium', 'palmitate', 'estolate', 'lactate', 'gluconate', 'carbonate', 'oxide',
    ];

    private const TAGS = ['bp', 'usp', 'ph eur', 'ph.eur', 'eur', 'inn', 'ip', 'jp', 'micronized', 'micronised', 'ophthalmic', 'topical', 'nasal'];

    /** Salts that ARE the identity of the molecule (stripping them would merge different generics). */
    private const KEEP_SALT_FOR = ['ferrous sulfate', 'zinc sulfate', 'calcium carbonate', 'magnesium sulfate', 'sodium chloride', 'potassium chloride', 'sodium bicarbonate', 'sodium valproate', 'calcium carbonate + vitamin d3'];

    /** @var array<string, Generic|null> */
    private array $memo = [];

    public function __construct(private readonly float $trigramThreshold = 0.92) {}

    /**
     * @return array{name: string, slug: string, salt: string|null, parts: list<string>}
     */
    public function normalise(string $text): array
    {
        $raw = Str::lower(trim($text));
        $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
        $raw = preg_replace('/\([^)]*\)/', ' ', $raw) ?? $raw;                                                   // (as trihydrate)
        $raw = str_replace(['&', ' and ', ' with '], ' + ', ' '.$raw.' ');
        $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);

        $parts = array_values(array_filter(array_map('trim', preg_split('/\s*\+\s*/', $raw) ?: []), fn ($p) => $p !== ''));
        $cleanParts = [];
        $salt = null;

        foreach ($parts as $part) {
            if (in_array($part, self::KEEP_SALT_FOR, true)) {
                $cleanParts[] = $part;

                continue;
            }

            $words = explode(' ', $part);
            $kept = [];

            foreach ($words as $word) {
                $w = trim($word, '.,;');

                if ($w === '' || in_array($w, self::TAGS, true)) {
                    continue;
                }

                if (in_array($w, self::SALTS, true) && count($words) > 1) {
                    $salt = $w;

                    continue;
                }

                $kept[] = $w;
            }

            $cleanParts[] = implode(' ', $kept);
        }

        $name = implode(' + ', $cleanParts);

        return ['name' => $name, 'slug' => Str::slug($name), 'salt' => $salt, 'parts' => $cleanParts];
    }

    /** Existing generics only. */
    public function resolve(string $text): ?Generic
    {
        $key = Str::lower(trim($text));

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->lookup($text);
    }

    public function resolveOrCreate(string $text, int $versionId, ImportRow $row): Generic
    {
        $found = $this->resolve($text);

        if ($found !== null) {
            return $found;
        }

        $n = $this->normalise($text);
        $componentIds = [];

        if (count($n['parts']) > 1) {
            foreach ($n['parts'] as $part) {
                $component = $this->lookup($part);

                if ($component !== null) {
                    $componentIds[] = ['generic_id' => $component->id, 'mg' => null];
                }
            }
        }

        $slug = $n['slug'] !== '' ? $n['slug'] : Str::slug($text);
        $name = Str::title($n['name'] !== '' ? $n['name'] : trim($text));

        $generic = Generic::query()->create([
            'name' => $name,
            'slug' => $slug,
            'aliases' => [],
            'components' => $componentIds === [] ? null : $componentIds,
            'needs_review' => true,
            'catalog_version_id' => $versionId,
            'is_active' => true,
        ]);

        CatalogImportIssue::query()->create([
            'catalog_version_id' => $versionId,
            'kind' => CatalogImportIssueKind::UnknownGeneric,
            'source_row' => $row->sourceRow,
            'payload' => $row->toPayload() + ['created_generic_id' => $generic->id],
        ]);

        return $this->memo[Str::lower(trim($text))] = $generic;
    }

    private function lookup(string $text): ?Generic
    {
        $n = $this->normalise($text);
        $candidates = array_values(array_unique(array_filter([$n['slug'], Str::slug($text)])));

        if ($candidates === []) {
            return null;
        }

        $bySlug = Generic::query()->whereIn('slug', $candidates)->orderBy('id')->first();

        if ($bySlug !== null) {
            return $bySlug;
        }

        $names = array_values(array_unique([$n['name'], Str::lower(trim($text))]));

        $byAlias = Generic::query()
            ->where(function ($q) use ($names): void {
                foreach ($names as $name) {
                    $q->orWhereRaw('lower(name) = ?', [$name])
                        ->orWhereRaw('EXISTS (SELECT 1 FROM jsonb_array_elements_text(aliases) a WHERE lower(a) = ?)', [$name]);
                }
            })
            ->orderBy('id')
            ->first();

        if ($byAlias !== null) {
            return $byAlias;
        }

        if ($n['name'] === '') {
            return null;
        }

        return Generic::query()
            ->whereRaw('similarity(lower(name), ?) >= ?', [$n['name'], $this->trigramThreshold])
            ->orderByRaw('similarity(lower(name), ?) DESC', [$n['name']])
            ->first();
    }
}
