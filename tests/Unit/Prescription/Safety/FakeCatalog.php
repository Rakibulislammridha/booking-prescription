<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Safety;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Prescription\Data\ParseContext;
use App\Domain\Prescription\Data\ParsedLine;
use App\Domain\Prescription\Data\PatientSafetyProfile;
use App\Domain\Prescription\Data\SafetyItem;
use App\Domain\Prescription\Enums\SafetyStage;
use App\Domain\Prescription\Safety\DailyDoseCalculator;
use App\Domain\Prescription\Safety\SafetyContext;
use App\Domain\Prescription\Shorthand\ShorthandParser;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use ReflectionProperty;

/**
 * CatalogCache::fake() needs the container; unit tests build the same fake through the constructor + reflection so
 * the safety checks stay pure PHPUnit (CONVENTIONS §6.2). Generic ids used across the suite: 17 Paracetamol,
 * 203 Warfarin, 5 Aspirin, 30 Amoxicillin (penicillins class 1), 31 Cefixime (cephalosporins class 2), 40 Ibuprofen,
 * 50 Warfarin-like NSAID "Diclofenac", 60 Amoxiclav (combination 30 + 61 Clavulanic acid).
 */
final class FakeCatalog
{
    /** @param  array<string, array<int|string, mixed>>  $tables */
    public static function make(array $tables = []): CatalogCache
    {
        $cache = new CatalogCache(new Repository(new ArrayStore));
        $fake = new ReflectionProperty(CatalogCache::class, 'fake');
        $fake->setValue($cache, $tables + self::defaults());
        $version = new ReflectionProperty(CatalogCache::class, 'version');
        $version->setValue($cache, 'fake');

        return $cache;
    }

    /** @return array<string, array<int|string, mixed>> */
    public static function defaults(): array
    {
        $generic = fn (int $id, string $name, string $class, bool $active = true, ?array $components = null, bool $weightBased = false) => [
            'id' => $id, 'name' => $name, 'slug' => strtolower($name), 'therapeutic_class' => $class, 'is_active' => $active, 'components' => $components, 'is_pediatric_weight_based' => $weightBased, 'aliases' => [],
        ];

        return [
            'generics' => [
                17 => $generic(17, 'Paracetamol', 'Analgesic / antipyretic', true, null, true),
                203 => $generic(203, 'Warfarin', 'Anticoagulant'),
                5 => $generic(5, 'Aspirin', 'Antiplatelet'),
                30 => $generic(30, 'Amoxicillin', 'Penicillin antibiotic'),
                31 => $generic(31, 'Cefixime', 'Cephalosporin antibiotic'),
                40 => $generic(40, 'Ibuprofen', 'NSAID'),
                50 => $generic(50, 'Diclofenac', 'NSAID'),
                60 => $generic(60, 'Amoxicillin + Clavulanic acid', 'Penicillin antibiotic', true, [['generic_id' => 30, 'mg' => 500], ['generic_id' => 61, 'mg' => 125]]),
                61 => $generic(61, 'Clavulanic acid', 'Beta-lactamase inhibitor'),
                70 => $generic(70, 'Ranitidine', 'H2 blocker', false),
            ],
            'brands' => [
                88 => ['id' => 88, 'generic_id' => 17, 'name' => 'Napa', 'manufacturer' => 'Beximco', 'is_active' => true],
                89 => ['id' => 89, 'generic_id' => 17, 'name' => 'Ace', 'manufacturer' => 'Square', 'is_active' => true],
                90 => ['id' => 90, 'generic_id' => 17, 'name' => 'OldNapa', 'manufacturer' => 'X', 'is_active' => false],
                91 => ['id' => 91, 'generic_id' => 203, 'name' => 'Warf', 'manufacturer' => 'Y', 'is_active' => true],
            ],
            'strengths' => [
                1234 => ['id' => 1234, 'brand_id' => 88, 'generic_id' => 17, 'strength_label' => '500 mg', 'strength_mg' => 500, 'per_ml' => null, 'is_active' => true, 'route_id' => 1, 'dosage_form_id' => 3],
                1235 => ['id' => 1235, 'brand_id' => 89, 'generic_id' => 17, 'strength_label' => '500 mg', 'strength_mg' => 500, 'per_ml' => null, 'is_active' => true, 'route_id' => 1, 'dosage_form_id' => 3],
                1236 => ['id' => 1236, 'brand_id' => 88, 'generic_id' => 17, 'strength_label' => '120 mg/5 ml', 'strength_mg' => 120, 'per_ml' => 24, 'is_active' => false, 'route_id' => 1, 'dosage_form_id' => 4],
                2001 => ['id' => 2001, 'brand_id' => 91, 'generic_id' => 203, 'strength_label' => '5 mg', 'strength_mg' => 5, 'per_ml' => null, 'is_active' => true, 'route_id' => 1, 'dosage_form_id' => 3],
            ],
            'drug_interactions' => [
                '5:203' => ['generic_a_id' => 5, 'generic_b_id' => 203, 'severity' => 'contraindicated', 'mechanism' => 'Additive antiplatelet and anticoagulant effect', 'effect' => 'Major bleeding risk', 'management' => 'Avoid; if unavoidable monitor INR', 'evidence_level' => 'established', 'source' => 'catalog v2026.09'],
                '40:203' => ['generic_a_id' => 40, 'generic_b_id' => 203, 'severity' => 'major', 'mechanism' => 'Platelet inhibition', 'effect' => 'GI bleeding and INR increase', 'management' => 'Avoid NSAIDs', 'evidence_level' => 'established', 'source' => 'catalog'],
                '17:203' => ['generic_a_id' => 17, 'generic_b_id' => 203, 'severity' => 'minor', 'mechanism' => null, 'effect' => 'Possible INR increase with regular use', 'management' => 'Monitor', 'evidence_level' => 'probable', 'source' => 'catalog'],
            ],
            'allergy_classes' => [
                1 => ['id' => 1, 'name' => 'Penicillins', 'slug' => 'penicillins', 'cross_reacts_with' => [['allergy_class_id' => 2, 'probability_pct' => 10]], 'is_active' => true],
                2 => ['id' => 2, 'name' => 'Cephalosporins', 'slug' => 'cephalosporins', 'cross_reacts_with' => [['allergy_class_id' => 1, 'probability_pct' => 10]], 'is_active' => true],
                3 => ['id' => 3, 'name' => 'NSAIDs', 'slug' => 'nsaids', 'cross_reacts_with' => [], 'is_active' => true],
            ],
            'allergy_class_generics' => [1 => [30, 60], 2 => [31], 3 => [40, 50, 5]],
            'generic_allergy_classes' => [30 => [1], 60 => [1], 31 => [2], 40 => [3], 50 => [3], 5 => [3]],
            'max_daily_doses' => [
                17 => [
                    ['generic_id' => 17, 'route_id' => null, 'population' => 'adult', 'max_mg_per_day' => 4000, 'max_mg_per_kg_per_day' => null, 'max_mg_per_dose' => 1000, 'min_age_months' => null, 'max_age_months' => null],
                    ['generic_id' => 17, 'route_id' => null, 'population' => 'pediatric', 'max_mg_per_day' => null, 'max_mg_per_kg_per_day' => 60, 'max_mg_per_dose' => null, 'min_age_months' => 3, 'max_age_months' => 215],
                    ['generic_id' => 17, 'route_id' => null, 'population' => 'elderly', 'max_mg_per_day' => 3000, 'max_mg_per_kg_per_day' => null, 'max_mg_per_dose' => 1000, 'min_age_months' => null, 'max_age_months' => null],
                ],
                40 => [
                    ['generic_id' => 40, 'route_id' => null, 'population' => 'adult', 'max_mg_per_day' => 2400, 'max_mg_per_kg_per_day' => null, 'max_mg_per_dose' => 800, 'min_age_months' => null, 'max_age_months' => null],
                    ['generic_id' => 40, 'route_id' => null, 'population' => 'pediatric', 'max_mg_per_day' => null, 'max_mg_per_kg_per_day' => 40, 'max_mg_per_dose' => null, 'min_age_months' => 6, 'max_age_months' => 215],
                ],
            ],
            'pregnancy_categories' => [
                203 => [['generic_id' => 203, 'trimester' => null, 'category' => 'X', 'lactation' => 'safe', 'notes' => null]],
                40 => [['generic_id' => 40, 'trimester' => null, 'category' => 'C', 'lactation' => 'caution', 'notes' => null], ['generic_id' => 40, 'trimester' => 3, 'category' => 'D', 'lactation' => 'caution', 'notes' => 'Third trimester: avoid.']],
                17 => [['generic_id' => 17, 'trimester' => null, 'category' => 'B', 'lactation' => 'safe', 'notes' => null]],
            ],
            'renal_cautions' => [40 => [['generic_id' => 40, 'egfr_below' => null, 'level' => 'avoid', 'advice' => 'Avoid in significant renal impairment.']], 17 => [['generic_id' => 17, 'egfr_below' => 30, 'level' => 'caution', 'advice' => 'Increase dosing interval.']]],
            'hepatic_cautions' => [17 => [['generic_id' => 17, 'child_pugh_class' => null, 'level' => 'adjust_dose', 'advice' => 'Max 2 g/day.']]],
        ];
    }

    /**
     * A SafetyItem for a tablet presentation with a parsed shorthand.
     *
     * @param  array<string, mixed>|null  $customBrand
     */
    public static function tablet(string $key, int $genericId, string $shorthand, float $strengthMg = 500, ?int $brandId = null, ?int $strengthId = null, ?int $customBrandId = null, ?array $customBrand = null, ?string $routeCode = null, string $name = ''): SafetyItem
    {
        $ctx = new ParseContext(formCode: 'tab', defaultUnit: 'tab', strengthMg: $strengthMg, strengthLabel: $strengthMg.' mg', formLabel: 'tablet', routeCode: 'po');
        $parsed = (new ShorthandParser)->parse($shorthand, $ctx);

        return self::item($key, $genericId, $parsed, $strengthMg, null, 'tab', $routeCode ?? $parsed->routeCode ?? 'po', $brandId, $strengthId, $customBrandId, $customBrand, $name);
    }

    /**
     * @param  array<string, mixed>|null  $customBrand
     */
    public static function item(string $key, ?int $genericId, ?ParsedLine $parsed, ?float $strengthMg, ?float $perMl, ?string $formCode, ?string $routeCode, ?int $brandId = null, ?int $strengthId = null, ?int $customBrandId = null, ?array $customBrand = null, string $name = ''): SafetyItem
    {
        return new SafetyItem(
            key: $key, genericId: $genericId, brandId: $brandId, customBrandId: $customBrandId, strengthId: $strengthId, strengthMg: $strengthMg, perMl: $perMl,
            formCode: $formCode, routeCode: $routeCode, routeId: 1, doseJson: $parsed,
            dailyMg: DailyDoseCalculator::dailyMg($parsed, $strengthMg, $perMl, $formCode), perDoseMg: DailyDoseCalculator::perDoseMg($parsed, $strengthMg, $perMl, $formCode),
            genericName: $name !== '' ? $name : "generic {$genericId}", brandName: null, isSystemic: true, customBrand: $customBrand,
        );
    }

    /**
     * @param  list<SafetyItem>  $items
     * @param  array<string, mixed>  $patient
     * @param  array<string, array{reason: string, by: int|null, at: string|null}>  $overrides
     */
    public static function context(array $items, array $patient = [], array $overrides = [], SafetyStage $stage = SafetyStage::Draft): SafetyContext
    {
        return new SafetyContext(1, $stage, self::adult($patient), $items, $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function adult(array $overrides = []): PatientSafetyProfile
    {
        $p = $overrides + ['ageMonths' => 34 * 12, 'weightKg' => 60.0, 'sex' => 'male', 'isPregnant' => false, 'isLactating' => false, 'trimester' => null, 'renalImpairment' => false, 'hepaticImpairment' => false,
            'allergyGenericIds' => [], 'allergyClassIds' => [], 'allergyTexts' => [], 'allergySeverities' => [], 'currentMedicationGenericIds' => [], 'currentMedicationNames' => [], 'pregnancyStatusKnown' => false];

        return new PatientSafetyProfile(...$p);
    }
}
