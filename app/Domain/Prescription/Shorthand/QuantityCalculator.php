<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Shorthand;

use App\Domain\Prescription\Data\ParseContext;
use App\Domain\Prescription\Data\ParsedLine;
use App\Domain\Prescription\Data\ParseIssue;

/**
 * PRESCRIPTION.md §2.12: quantity to dispense per counting family (counted · liquid · insulin · inhaler · packs),
 * rounding up, pack sizes, `x` overrides, cont/tf. Also emits missing_duration / missing_schedule /
 * quantity_unknown / continuous_assumed per §2.5, §2.9 and §2.13. Pure.
 */
final class QuantityCalculator
{
    public static function compute(ParsedLine $line, ?ParseContext $ctx): void
    {
        if ($line->hasErrors()) {
            return;
        }

        $ctx ??= new ParseContext;
        $unit = $line->unit;
        $family = Keywords::familyOf($unit);
        $packUnit = $ctx->packUnit ?? (Keywords::forms()[$ctx->formCode ?? '']['pack_unit'] ?? self::defaultPackUnit($family, $unit));
        $override = $line->quantity['source'] === 'override' ? $line->quantity : null;
        $days = $line->effectiveDays();
        $schedule = $line->schedule;
        $isPackForm = in_array($family, ['packs', 'inhaler'], true);

        if (($line->duration['type'] ?? null) === 'continuous') {
            self::issue($line, 'continuous_assumed', 'info', 'continuous_assumed', ['{days}' => (string) $line->duration['assumed_days']]);
        }

        if ($schedule === null) {
            if ($override !== null) {
                self::override($line, $override, $family, $unit, $packUnit);

                return;
            }

            if ($isPackForm) {
                $line->quantity = ['value' => 1, 'unit' => $packUnit, 'source' => 'auto', 'basis' => "1 {$packUnit}"];

                return;
            }

            self::issue($line, 'missing_schedule', 'warning', 'missing_schedule');
            $line->quantity = ['value' => null, 'unit' => $unit, 'source' => 'none', 'basis' => null];

            return;
        }

        $type = $schedule['type'];
        $daily = $line->dailyTotal;

        if ($override !== null) {
            self::override($line, $override, $family, $unit, $packUnit);

            return;
        }

        if ($type === 'stat') {
            $amount = (float) $schedule['amount'];
            $line->quantity = match ($family) {
                'counted' => ['value' => self::num(ceil($amount)), 'unit' => $unit, 'source' => 'auto', 'basis' => 'stat = '.NumberFormat::decimal(ceil($amount)).' '.$unit],
                'liquid' => ['value' => self::num($amount * Keywords::liquidMl()[$unit]), 'unit' => 'ml', 'source' => 'auto', 'basis' => 'stat = '.NumberFormat::decimal($amount * Keywords::liquidMl()[$unit]).' ml'],
                default => ['value' => 1, 'unit' => $packUnit, 'source' => 'auto', 'basis' => "1 {$packUnit}"],
            };

            return;
        }

        if ($type === 'sos' && $schedule['max_per_day'] === null) {
            if ($isPackForm) {
                $line->quantity = ['value' => 1, 'unit' => $packUnit, 'source' => 'auto', 'basis' => "1 {$packUnit}"];

                return;
            }

            self::issue($line, 'quantity_unknown', 'warning', 'quantity_unknown');
            $line->quantity = ['value' => null, 'unit' => $unit, 'source' => 'none', 'basis' => null];

            return;
        }

        if (($line->duration['type'] ?? null) === 'till_finish') {
            if ($isPackForm) {
                $line->quantity = ['value' => 1, 'unit' => $packUnit, 'source' => 'auto', 'basis' => "1 {$packUnit}"];

                return;
            }

            self::issue($line, 'quantity_unknown', 'warning', 'quantity_unknown');
            $line->quantity = ['value' => null, 'unit' => $unit, 'source' => 'none', 'basis' => null];

            return;
        }

        if ($days === null && ! $isPackForm) {
            self::issue($line, 'missing_duration', 'warning', 'missing_duration');
            $line->quantity = ['value' => null, 'unit' => $unit, 'source' => 'none', 'basis' => null];

            return;
        }

        $daily = (float) $daily;

        switch ($family) {
            case 'counted':
                $raw = $daily * $days;
                $value = (int) ceil($raw - 1e-9);
                $basis = NumberFormat::decimal($daily)."/day × {$days} d = ".(abs($raw - $value) > 1e-9 ? NumberFormat::decimal($raw).' → ' : '')."{$value} {$unit}";
                $line->quantity = ['value' => $value, 'unit' => $unit, 'source' => 'auto', 'basis' => $basis];
                break;

            case 'liquid':
                $mlPerDay = $daily * Keywords::liquidMl()[$unit];
                $totalMl = $mlPerDay * $days;
                if ($ctx->packSize !== null && $ctx->packSize > 0) {
                    $bottles = (int) ceil($totalMl / $ctx->packSize - 1e-9);
                    $line->quantity = ['value' => $bottles, 'unit' => $packUnit, 'source' => 'auto',
                        'basis' => NumberFormat::decimal($mlPerDay)." ml/day × {$days} d = ".NumberFormat::decimal($totalMl)." ml → {$bottles} × ".NumberFormat::decimal($ctx->packSize).' ml'];
                } else {
                    self::issue($line, 'quantity_unknown', 'info', 'quantity_pack_unknown');
                    $line->quantity = ['value' => self::num($totalMl), 'unit' => 'ml', 'source' => 'auto', 'basis' => NumberFormat::decimal($mlPerDay)." ml/day × {$days} d = ".NumberFormat::decimal($totalMl).' ml'];
                }
                break;

            case 'insulin':
                $total = $daily * $days;
                $pack = $ctx->packSize ?? Keywords::defaultPackSize('insulin') ?? 1000.0;
                $packs = (int) ceil($total / $pack - 1e-9);
                $line->quantity = ['value' => $packs, 'unit' => $packUnit, 'source' => 'auto',
                    'basis' => NumberFormat::decimal($daily)." unit/day × {$days} d = ".NumberFormat::decimal($total)." unit → {$packs} {$packUnit}"];
                break;

            case 'inhaler':
                if ($days === null) {
                    $line->quantity = ['value' => 1, 'unit' => $packUnit, 'source' => 'auto', 'basis' => "1 {$packUnit}"];
                    break;
                }
                $total = $daily * $days;
                $pack = $ctx->packSize ?? Keywords::defaultPackSize('inhaler') ?? 200.0;
                $packs = (int) ceil($total / $pack - 1e-9);
                $line->quantity = ['value' => $packs, 'unit' => $packUnit, 'source' => 'auto',
                    'basis' => NumberFormat::decimal($daily)." puff/day × {$days} d = ".NumberFormat::decimal($total)." puff → {$packs} {$packUnit}"];
                break;

            default:                                                   // packs: drop / spray / app
                $packs = $days === null ? 1 : max(1, (int) ceil($days / Keywords::packDays() - 1e-9));
                $line->quantity = ['value' => $packs, 'unit' => $packUnit, 'source' => 'auto', 'basis' => $days === null ? "1 {$packUnit}" : "{$packs} {$packUnit} ({$days} d)"];
        }
    }

    /** @param  array<string, mixed>  $override */
    private static function override(ParsedLine $line, array $override, string $family, string $unit, string $packUnit): void
    {
        $pack = $override['_pack'] ?? null;
        $dispenseUnit = $pack ?? match ($family) {
            'counted' => $unit,
            default => $packUnit,
        };

        $line->quantity = ['value' => (int) $override['value'], 'unit' => $dispenseUnit, 'source' => 'override', 'basis' => 'override'];
    }

    private static function defaultPackUnit(string $family, string $unit): string
    {
        return match ($family) {
            'liquid' => 'bottle',
            'insulin' => 'vial',
            'inhaler' => 'inhaler',
            'packs' => $unit === 'app' ? 'tube' : 'bottle',
            default => $unit,
        };
    }

    private static function num(float $v): float|int
    {
        return abs($v - round($v)) < 1e-9 ? (int) round($v) : round($v, 2);
    }

    /** @param  array<string, string>  $params */
    private static function issue(ParsedLine $line, string $code, string $severity, string $messageKey, array $params = []): void
    {
        $m = Keywords::message($messageKey);
        $line->issues[] = new ParseIssue($code, $severity, null, null, strtr($m['en'], $params), strtr($m['bn'], $params), null);
    }
}
