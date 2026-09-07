<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety\Checks;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Prescription\Data\SafetyItem;
use App\Domain\Prescription\Enums\SafetySeverity as Severity;
use App\Domain\Prescription\Safety\GenericExpander;
use App\Domain\Prescription\Safety\SafetyAlert;
use App\Domain\Prescription\Safety\SafetyCheck;
use App\Domain\Prescription\Safety\SafetyContext;
use Illuminate\Support\Str;

/**
 * PRESCRIPTION.md §5.3 AllergyCheck: direct generic hit / class hit (explicit class, or the class of an allergen
 * generic) → critical; cross-reactivity via allergy_classes.cross_reacts_with → warning (critical when
 * probability ≥ 10 % and the patient's reaction is severe); free-text allergen fuzzy-matched (trigram ≥ 0.6) →
 * warning; unmatched free text → one info "verify allergy".
 */
final class AllergyCheck implements SafetyCheck
{
    private GenericExpander $expander;

    public function __construct(private readonly CatalogCache $catalog)
    {
        $this->expander = new GenericExpander($catalog);
    }

    public function key(): string
    {
        return 'allergy';
    }

    /** @return list<SafetyAlert> */
    public function run(SafetyContext $ctx): array
    {
        $p = $ctx->patient;
        $items = $ctx->itemsWithGeneric();

        if ($items === []) {
            return [];
        }

        $alerts = [];
        $patientClasses = [];                                         // class id → severity

        foreach ($p->allergyClassIds as $classId) {
            $patientClasses[$classId] = $p->allergySeverities[$classId] ?? null;
        }

        foreach ($p->allergyGenericIds as $genericId) {
            foreach ($this->catalog->genericAllergyClasses($genericId) as $classId) {
                $patientClasses[$classId] ??= $p->allergySeverities[$genericId] ?? null;
            }
        }

        $matchedTexts = [];

        foreach ($items as $item) {
            $generics = $this->expander->ids((int) $item->genericId);

            foreach ($generics as $g) {
                $name = $this->expander->name($g);

                if (in_array($g, $p->allergyGenericIds, true)) {
                    $alerts[] = $this->alert($item, 'allergy.direct', Severity::Critical, "allergy:direct:{$g}", 'Documented allergy',
                        "{$name} is on the patient's allergy list.", "{$name} রোগীর অ্যালার্জি তালিকায় আছে।", [$g],
                        ['allergen' => $name, 'match' => 'generic', 'severity' => $p->allergySeverities[$g] ?? null]);

                    continue;
                }

                $itemClasses = $this->catalog->genericAllergyClasses($g);
                $classHit = false;

                foreach ($itemClasses as $classId) {
                    if (array_key_exists($classId, $patientClasses)) {
                        $class = $this->catalog->allergyClass($classId);
                        $className = (string) ($class['name'] ?? "class #{$classId}");
                        $alerts[] = $this->alert($item, 'allergy.class', Severity::Critical, "allergy:class:{$g}:{$classId}", 'Allergy class match',
                            "{$name} belongs to {$className}, a class the patient is allergic to.", "{$name} {$className} শ্রেণির ওষুধ, যাতে রোগীর অ্যালার্জি আছে।", [$g],
                            ['allergen' => $className, 'match' => 'class', 'allergy_class_id' => $classId, 'severity' => $patientClasses[$classId]]);
                        $classHit = true;
                    }
                }

                if ($classHit) {
                    continue;
                }

                foreach ($patientClasses as $classId => $severity) {
                    $class = $this->catalog->allergyClass($classId);

                    foreach ((array) ($class['cross_reacts_with'] ?? []) as $cross) {
                        $crossId = (int) ($cross['allergy_class_id'] ?? 0);

                        if ($crossId === 0 || ! in_array($crossId, $itemClasses, true)) {
                            continue;
                        }

                        $pct = (float) ($cross['probability_pct'] ?? 0);
                        $crossName = (string) ($this->catalog->allergyClass($crossId)['name'] ?? "class #{$crossId}");
                        $sev = $pct >= 10 && $severity === 'severe' ? Severity::Critical : Severity::Warning;
                        $alerts[] = $this->alert($item, 'allergy.cross', $sev, "allergy:cross:{$g}:{$crossId}", 'Possible cross-reactivity',
                            "{$name} ({$crossName}) may cross-react with the patient's ".$class['name']." allergy (~{$pct}%).",
                            "{$name} ({$crossName}) রোগীর ".$class['name']." অ্যালার্জির সাথে ক্রস-রিঅ্যাক্ট করতে পারে (~{$pct}%)।", [$g],
                            ['allergen' => $class['name'], 'match' => 'cross', 'cross_class' => $crossName, 'probability_pct' => $pct, 'severity' => $severity]);
                    }
                }

                foreach ($p->allergyTexts as $text) {
                    $candidates = [$name, ...array_map(fn ($c) => (string) ($this->catalog->allergyClass($c)['name'] ?? ''), $itemClasses)];
                    $score = max(array_map(fn ($c) => self::trigramSimilarity((string) $text['name'], $c), $candidates));

                    if ($score >= 0.6) {
                        $matchedTexts[$text['name']] = true;
                        $alerts[] = $this->alert($item, 'allergy.text', Severity::Warning, 'allergy:text:'.$g.':'.Str::slug($text['name']), 'Allergy note may match',
                            "Allergy note \"{$text['name']}\" looks like {$name}.", "অ্যালার্জি নোট \"{$text['name']}\" {$name}-এর মতো দেখাচ্ছে।", [$g],
                            ['allergen' => $text['name'], 'match' => 'text', 'similarity' => round($score, 2), 'severity' => $text['severity']]);
                    }
                }
            }
        }

        foreach ($p->allergyTexts as $text) {
            if (! isset($matchedTexts[$text['name']])) {
                $slug = Str::slug($text['name']) ?: md5($text['name']);
                $alerts[] = new SafetyAlert($this->key(), 'allergy.unverified', Severity::Info, "allergy:unverified:{$slug}", true,
                    'Verify allergy', "Verify allergy: \"{$text['name']}\" (not linked to a drug).", "অ্যালার্জি যাচাই করুন: \"{$text['name']}\" (কোনো ওষুধের সাথে যুক্ত নয়)।",
                    [], [], ['allergen' => $text['name'], 'match' => 'none']);
            }
        }

        return $alerts;
    }

    /**
     * @param  list<int>  $generics
     * @param  array<string, mixed>  $evidence
     */
    private function alert(SafetyItem $item, string $code, Severity $severity, string $fingerprint, string $title, string $message, string $messageBn, array $generics, array $evidence): SafetyAlert
    {
        return new SafetyAlert($this->key(), $code, $severity, $fingerprint, true, $title, $message, $messageBn, [$item->key], $generics, $evidence);
    }

    /** pg_trgm-style similarity: shared trigrams / union, over lower-cased padded words. */
    public static function trigramSimilarity(string $a, string $b): float
    {
        $ta = self::trigrams($a);
        $tb = self::trigrams($b);

        if ($ta === [] || $tb === []) {
            return 0.0;
        }

        $shared = count(array_intersect_key($ta, $tb));
        $union = count($ta + $tb);

        return $shared / $union;
    }

    /** @return array<string, true> */
    private static function trigrams(string $s): array
    {
        $s = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s)));
        $out = [];

        foreach (preg_split('/\s+/u', $s) ?: [] as $word) {
            if ($word === '') {
                continue;
            }

            $padded = '  '.$word.' ';
            $len = mb_strlen($padded);

            for ($i = 0; $i <= $len - 3; $i++) {
                $out[mb_substr($padded, $i, 3)] = true;
            }
        }

        return $out;
    }
}
