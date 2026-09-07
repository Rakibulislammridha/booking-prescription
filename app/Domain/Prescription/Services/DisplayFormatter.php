<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Domain\Prescription\Data\ParsedLine;
use App\Domain\Prescription\Shorthand\Keywords;
use App\Domain\Prescription\Shorthand\NumberFormat;

/**
 * Both-language display strings frozen into the snapshot (PRESCRIPTION.md §6.2 items[].display, follow_up.label,
 * chief_complaints[].duration_label) and the writer's interpretation line (§2.13). Bangla renderings use Bangla
 * digits; drug names always stay English (rendered by the templates, not here).
 */
final class DisplayFormatter
{
    /** @return array{bn: array{dose: string, duration: string, timing: string, quantity: string, route: string, interpretation: string}, en: array{dose: string, duration: string, timing: string, quantity: string, route: string, interpretation: string}} */
    public function item(ParsedLine $line): array
    {
        return ['bn' => $this->itemIn('bn', $line), 'en' => $this->itemIn('en', $line)];
    }

    /** @return array{dose: string, duration: string, timing: string, quantity: string, route: string, interpretation: string} */
    public function itemIn(string $lang, ParsedLine $line): array
    {
        $labels = Keywords::labels();
        $bn = $lang === 'bn';
        $n = fn (string $s) => $bn ? NumberFormat::bnDigits($s) : $s;
        $unitLabel = (string) ($labels['units'][$line->unit][$lang] ?? $line->unit);
        $s = $line->schedule;
        $dose = '';

        if ($s !== null) {
            $dose = match ($s['type']) {
                'slots' => implode(' + ', array_map(fn ($v) => $n(NumberFormat::amount((float) $v)), $s['slots'])).($bn ? '' : ' '.$unitLabel),
                'frequency' => $n(NumberFormat::amount((float) $s['amount'])).($bn ? $this->bnCounter($line->unit) : ' '.$unitLabel).' '.($labels['schedule'][$s['code']][$lang] ?? $s['code']),
                'interval' => $n(NumberFormat::amount((float) $s['amount'])).($bn ? $this->bnCounter($line->unit) : ' '.$unitLabel).' '.strtr((string) $labels['schedule']['interval'][$lang], ['{hours}' => $n((string) $s['every_hours'])]),
                'stat' => $n(NumberFormat::amount((float) $s['amount'])).($bn ? $this->bnCounter($line->unit) : ' '.$unitLabel).' '.$labels['schedule']['stat'][$lang],
                'sos' => $n(NumberFormat::amount((float) $s['amount'])).($bn ? $this->bnCounter($line->unit) : ' '.$unitLabel).' '.($s['max_per_day'] !== null ? strtr((string) $labels['schedule']['sos_max'][$lang], ['{max}' => $n((string) $s['max_per_day'])]) : $labels['schedule']['sos'][$lang]),
                default => '',
            };
        }

        $duration = $this->duration($lang, $line);
        $timing = $line->timingCode !== null ? (string) ($labels['timing'][$line->timingCode][$lang] ?? '') : '';
        $route = $line->routeCode !== null ? (string) ($labels['routes'][$line->routeCode][$lang] ?? $line->routeCode) : '';
        $quantity = $this->quantity($lang, $line);
        $parts = array_values(array_filter([$dose, $duration, $timing, $route, $quantity], fn ($p) => $p !== ''));

        return ['dose' => $dose, 'duration' => $duration, 'timing' => $timing, 'quantity' => $quantity, 'route' => $route, 'interpretation' => implode(' · ', $parts)];
    }

    public function duration(string $lang, ParsedLine $line): string
    {
        $labels = Keywords::labels()['duration'];
        $d = $line->duration;

        if ($d === null) {
            return '';
        }

        return match ($d['type']) {
            'days' => $lang === 'bn' ? NumberFormat::bnDigits((string) $d['days']).' '.$labels['days']['bn'] : $d['days'].' '.($d['days'] === 1 ? $labels['day']['en'] : $labels['days']['en']),
            'continuous' => (string) $labels['continuous'][$lang],
            'till_finish' => (string) $labels['till_finish'][$lang],
            default => '',
        };
    }

    public function quantity(string $lang, ParsedLine $line): string
    {
        $q = $line->quantity;

        if ($q['value'] === null) {
            return '';
        }

        $labels = Keywords::labels()['units'];
        $value = NumberFormat::decimal((float) $q['value']);

        if ($lang === 'bn') {
            return NumberFormat::bnDigits($value).(in_array($q['unit'], ['tab', 'cap', 'supp', 'pessary', 'neb', 'sachet', 'respule'], true) ? 'টি' : ' '.($labels[$q['unit']]['bn'] ?? $q['unit']));
        }

        return $value.' '.($labels[$q['unit']]['en'] ?? $q['unit']);
    }

    /**
     * "3d" → {bn: "৩ দিন", en: "3 days"} (chief complaint durations, PRESCRIPTION.md §4.1).
     *
     * @return array{bn: string, en: string}
     */
    public function complaintDuration(?string $duration): array
    {
        if ($duration === null || trim($duration) === '') {
            return ['bn' => '', 'en' => ''];
        }

        if (preg_match('/^(\d+)\s*(d|day|days|w|wk|wks|week|weeks|m|mo|month|months|h|hr|hrs|hour|hours|y|yr|yrs|year|years)$/i', trim(NumberFormat::enDigits($duration)), $m) !== 1) {
            return ['bn' => NumberFormat::bnDigits($duration), 'en' => $duration];
        }

        $n = (int) $m[1];
        $unit = strtolower($m[2]);
        [$en, $bn] = match (true) {
            str_starts_with($unit, 'd') => [$n === 1 ? 'day' : 'days', 'দিন'],
            str_starts_with($unit, 'w') => [$n === 1 ? 'week' : 'weeks', 'সপ্তাহ'],
            str_starts_with($unit, 'm') => [$n === 1 ? 'month' : 'months', 'মাস'],
            str_starts_with($unit, 'h') => [$n === 1 ? 'hour' : 'hours', 'ঘণ্টা'],
            default => [$n === 1 ? 'year' : 'years', 'বছর'],
        };

        return ['bn' => NumberFormat::bnDigits((string) $n).' '.$bn, 'en' => "{$n} {$en}"];
    }

    /**
     * Follow-up label: {bn: "৭ দিন পর", en: "after 7 days"}.
     *
     * @return array{bn: string, en: string}
     */
    public function followUp(?int $days): array
    {
        if ($days === null) {
            return ['bn' => '', 'en' => ''];
        }

        return ['bn' => NumberFormat::bnDigits((string) $days).' দিন পর', 'en' => "after {$days} ".($days === 1 ? 'day' : 'days')];
    }

    private function bnCounter(string $unit): string
    {
        $labels = Keywords::labels()['units'];

        return in_array($unit, ['tab', 'cap', 'supp', 'pessary', 'neb', 'sachet'], true) ? 'টা' : ' '.($labels[$unit]['bn'] ?? $unit);
    }
}
