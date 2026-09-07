<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import;

use App\Domain\Catalog\Data\ParsedStrength;
use InvalidArgumentException;

/**
 * Parses presentation labels (CATALOG.md §5.3): `500 mg`, `665 mg XR`, `120 mg/5 ml`, `80 mg/ml`, `25/125 mcg`, `0.5 %`,
 * `100 IU/ml`, `1 g/100 ml`, `2.5 mg/2.5 ml`, `100 mcg/actuation`, `ORS 20.5 g`. Pure — usable from unit tests.
 */
final class StrengthLabelParser
{
    private const UNIT_ALIASES = [
        'mg' => 'mg', 'mgs' => 'mg', 'g' => 'g', 'gm' => 'g', 'gram' => 'g', 'grams' => 'g',
        'mcg' => 'mcg', 'µg' => 'mcg', 'ug' => 'mcg', 'microgram' => 'mcg', 'micrograms' => 'mcg',
        'iu' => 'IU', 'i.u.' => 'IU', 'units' => 'IU', 'unit' => 'IU', 'u' => 'IU',
        '%' => '%', 'percent' => '%', 'ml' => 'ml', 'l' => 'l', 'meq' => 'mEq', 'mmol' => 'mmol',
    ];

    private const PER_ALIASES = [
        'ml' => 'ml', 'l' => 'l', 'actuation' => 'actuation', 'puff' => 'actuation', 'dose' => 'actuation', 'spray' => 'actuation',
        'drop' => 'drop', 'g' => 'g', 'gm' => 'g', 'sachet' => 'sachet', 'tab' => 'tab', 'tablet' => 'tab', 'cap' => 'cap', 'capsule' => 'cap',
        'vial' => 'vial', 'amp' => 'amp', 'ampoule' => 'amp',
    ];

    private const MODIFIERS = ['xr', 'sr', 'er', 'cr', 'mr', 'la', 'xl', 'ds', 'forte', 'plus', 'retard', 'pfs'];

    public function parse(string $label): ParsedStrength
    {
        return $this->tryParse($label) ?? throw new InvalidArgumentException("Unparsable strength label: {$label}");
    }

    public function tryParse(?string $label): ?ParsedStrength
    {
        if ($label === null || trim($label) === '') {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);
        $text = str_replace(['µ', 'μ'], 'u', $text);

        // amount(s) [unit] [/ per_value per_unit] [modifier]; a leading word (ORS 20.5 g) is tolerated.
        $number = '(\d+(?:[.,]\d+)?)';
        $pattern = '~^(?:[A-Za-z][A-Za-z0-9\-]*\s+)*?'
            .$number.'((?:\s*/\s*'.$number.')*)\s*([A-Za-z%.]+)?'                                 // amounts + unit
            .'(?:\s*/\s*(?:'.$number.'\s*)?([A-Za-z]+))?'                                            // per part
            .'(?:\s+([A-Za-z][A-Za-z\-]*))?\s*$~iu';

        if (! preg_match($pattern, $text, $m)) {
            return null;
        }

        $components = [(float) str_replace(',', '.', $m[1])];

        if ($m[2] !== '') {
            preg_match_all('~'.$number.'~', $m[2], $extra);
            foreach ($extra[1] as $value) {
                $components[] = (float) str_replace(',', '.', $value);
            }
        }

        $unitRaw = strtolower(trim($m[4] ?? ''));
        $perValueRaw = $m[5] ?? '';
        $perUnitRaw = strtolower(trim($m[6] ?? ''));
        $modifierRaw = strtolower(trim($m[7] ?? ''));

        // A bare "500/5 ml" has the per-unit in the unit slot; a bare "0.5 %" has the unit but no per part.
        if ($unitRaw === '' && $perUnitRaw !== '' && ! isset(self::PER_ALIASES[$perUnitRaw])) {
            return null;
        }

        $amountUnit = self::UNIT_ALIASES[$unitRaw] ?? null;

        if ($amountUnit === null) {
            if ($unitRaw !== '' && in_array($unitRaw, self::MODIFIERS, true) && $modifierRaw === '') {
                $modifierRaw = $unitRaw;
                $amountUnit = 'mg';
            } elseif ($unitRaw === '' && $perUnitRaw === '') {
                return null;                                                                              // "500" alone is not a strength
            } else {
                return null;
            }
        }

        if ($modifierRaw !== '' && ! in_array($modifierRaw, self::MODIFIERS, true)) {
            return null;
        }

        $perUnit = $perUnitRaw === '' ? null : (self::PER_ALIASES[$perUnitRaw] ?? null);

        if ($perUnitRaw !== '' && $perUnit === null) {
            return null;
        }

        $perValue = $perUnit === null ? null : ($perValueRaw === '' ? 1.0 : (float) str_replace(',', '.', $perValueRaw));
        $amountValue = $components[0];
        $strengthMg = $this->toMg($amountValue, $amountUnit);
        $perMl = null;

        if ($amountUnit === '%') {
            $perMl = $amountValue * 10;                                                                   // w/v: 1 % = 10 mg/ml
            $strengthMg = null;
        } elseif ($perUnit === 'ml' && $strengthMg !== null && $perValue !== null && $perValue > 0) {
            $perMl = $strengthMg / $perValue;
        } elseif ($perUnit === 'l' && $strengthMg !== null && $perValue !== null && $perValue > 0) {
            $perMl = $strengthMg / ($perValue * 1000);
        }

        $modifier = $modifierRaw === '' ? null : strtoupper($modifierRaw);

        return new ParsedStrength(
            label: $this->normaliseLabel($components, $amountUnit, $perValue, $perUnit, $modifier),
            amountValue: $amountValue,
            amountUnit: $amountUnit,
            perValue: $perValue,
            perUnit: $perUnit,
            strengthMg: $strengthMg === null ? null : round($strengthMg, 4),
            perMl: $perMl === null ? null : round($perMl, 4),
            modifier: $modifier,
            components: $components,
        );
    }

    /**
     * `100 ml` → [100, 'ml']; `10x10` → [100, 'tab' (default unit)]; `200 doses` → [200, 'actuation']; `3 ml pen` → [3, 'ml'].
     *
     * @return array{0: float|null, 1: string|null}
     */
    public function parsePack(?string $pack, ?string $defaultUnit = null): array
    {
        if ($pack === null || trim($pack) === '') {
            return [null, null];
        }

        $text = strtolower(trim($pack));

        if (preg_match('~^(\d+)\s*[x×]\s*(\d+)\s*([a-z]+)?~', $text, $m)) {
            return [(float) $m[1] * (float) $m[2], $this->packUnit($m[3] ?? '', $defaultUnit)];
        }

        if (preg_match('~^(\d+(?:\.\d+)?)\s*([a-z]+)?~', $text, $m)) {
            return [(float) $m[1], $this->packUnit($m[2] ?? '', $defaultUnit)];
        }

        return [null, null];
    }

    private function packUnit(string $raw, ?string $default): ?string
    {
        return match ($raw) {
            'ml' => 'ml',
            'l' => 'l',
            'g', 'gm' => 'g',
            'dose', 'doses', 'actuation', 'actuations', 'puff', 'puffs', 'spray', 'sprays' => 'actuation',
            'tab', 'tabs', 'tablet', 'tablets' => 'tab',
            'cap', 'caps', 'capsule', 'capsules' => 'cap',
            'sachet', 'sachets' => 'sachet',
            'vial', 'vials' => 'vial',
            'amp', 'amps', 'ampoule', 'ampoules' => 'amp',
            'pen', 'pens' => 'pen',
            'nebule', 'nebules', 'respule', 'respules' => 'respule',
            'pessary', 'pessaries', 'supp', 'suppository', 'suppositories' => 'unit',
            '' => $default,
            default => $raw,
        };
    }

    private function toMg(float $value, string $unit): ?float
    {
        return match ($unit) {
            'mg' => $value,
            'g' => $value * 1000,
            'mcg' => $value / 1000,
            'IU', 'mEq', 'mmol' => $value,                     // kept in their own unit (SCHEMA: "mg (or IU)")
            default => null,
        };
    }

    /** @param  list<float>  $components */
    private function normaliseLabel(array $components, string $unit, ?float $perValue, ?string $perUnit, ?string $modifier): string
    {
        $fmt = fn (float $v): string => rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
        $label = implode('/', array_map($fmt, $components)).' '.$unit;

        if ($perUnit !== null) {
            $label .= '/'.($perValue === 1.0 ? '' : $fmt((float) $perValue).' ').$perUnit;
        }

        return $modifier === null ? $label : $label.' '.$modifier;
    }
}
