<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Shorthand;

use App\Domain\Prescription\Data\ParseContext;
use App\Domain\Prescription\Data\ParsedLine;
use App\Domain\Prescription\Data\ParseIssue;

/**
 * Turns the token stream into a ParsedLine (PRESCRIPTION.md §2.4–§2.10): one schedule, one duration, one timing,
 * one route, one quantity override; unit inference and mg→unit conversion against the ParseContext; the
 * never-guess-silently issue policy (§2.13). Quantity is filled afterwards by QuantityCalculator.
 */
final class Assembler
{
    private string $raw = '';

    private int $spanCursor = 0;

    private bool $hsAlone = false;

    /** The doctor typed a unit word (tsp, drop, unit…) — mg/g/mcg conversions do not count (canonical spelling omits them). */
    private bool $unitTyped = false;

    private string $defaultUnit = 'tab';

    /** @param  list<Token>  $tokens */
    public function assemble(string $raw, string $body, ?string $instruction, array $tokens, ?ParseContext $ctx): ParsedLine
    {
        $this->raw = $raw;
        $this->spanCursor = 0;
        $this->hsAlone = false;
        $this->unitTyped = false;
        $this->defaultUnit = $ctx->defaultUnit ?? 'tab';
        $line = new ParsedLine($raw);
        $line->instruction = $instruction;

        if ($instruction !== null && mb_strlen($instruction) > 200) {
            $this->issue($line, 'instruction_too_long', 'error', null, 'instruction_too_long');
        }

        if ($ctx === null) {
            $this->issue($line, 'drug_missing', 'error', null, 'drug_missing');
        }

        $hasSchedule = array_filter($tokens, fn (Token $t) => in_array($t->type, ['slots', 'freq', 'interval', 'stat', 'sos'], true)) !== [];
        $pending = null;            // amount token waiting for its schedule keyword
        $qty = null;
        $scheduleText = null;
        $durationToken = null;
        $timingToken = null;
        $routeToken = null;

        foreach ($tokens as $token) {
            $span = $this->span($token->text);

            switch ($token->type) {
                case 'amount':
                    if ($pending !== null) {
                        $this->issue($line, 'unknown_token', 'error', $pending, 'amount_dangling', span: $this->spanOf($pending));
                    }
                    $pending = $token;
                    break;

                case 'slots':
                    if ($line->schedule !== null) {
                        $this->issue($line, 'duplicate_schedule', 'error', $token, 'duplicate_schedule', span: $span);
                        break;
                    }
                    $amounts = $token->data['amounts'];
                    if (count($amounts) > 4) {
                        $this->issue($line, 'slot_count', 'error', $token, 'slot_count', span: $span);
                        break;
                    }
                    $line->schedule = ['type' => 'slots', 'slots' => array_map(fn ($a) => $a['value'], $amounts), '_amounts' => $amounts];
                    $scheduleText = $token->text;
                    break;

                case 'freq':
                case 'interval':
                case 'stat':
                case 'sos':
                    if ($token->type === 'sos' && ($line->schedule['type'] ?? null) === 'frequency' && $pending === null) {
                        $line->schedule = ['type' => 'sos', 'amount' => $line->schedule['amount'], 'max_per_day' => $line->schedule['per_day'], '_amounts' => $line->schedule['_amounts']];
                        $scheduleText .= ' sos';
                        break;
                    }
                    if ($line->schedule !== null) {
                        $this->issue($line, 'duplicate_schedule', 'error', $token, 'duplicate_schedule', span: $span);
                        $pending = null;
                        break;
                    }
                    $amount = $pending->data ?? ['value' => 1.0, 'unit' => null, 'text' => '1'];
                    $line->schedule = match ($token->type) {
                        'freq' => ['type' => 'frequency', 'code' => $token->data['code'], 'per_day' => $token->data['per_day'], 'amount' => $amount['value']],
                        'interval' => ['type' => 'interval', 'every_hours' => $token->data['hours'], 'amount' => $amount['value']],
                        'stat' => ['type' => 'stat', 'amount' => $amount['value']],
                        default => ['type' => 'sos', 'amount' => $amount['value'], 'max_per_day' => null],
                    };
                    $line->schedule['_amounts'] = [$amount];
                    if ($token->type === 'interval' && ($token->data['hours'] <= 0 || 24 % $token->data['hours'] !== 0)) {
                        $this->issue($line, 'interval_invalid', 'error', $token, 'interval_invalid', span: $span);
                    }
                    $scheduleText = ($pending !== null ? $pending->text.' ' : '').$token->text;
                    $pending = null;
                    break;

                case 'max':
                    if (($line->schedule['type'] ?? null) === 'sos') {
                        $line->schedule['max_per_day'] = $token->data['value'];
                        $scheduleText .= ' max '.$token->data['value'];
                    } else {
                        $this->issue($line, 'max_without_sos', 'error', $token, 'max_without_sos', span: $span);
                    }
                    break;

                case 'hs':
                    if ($hasSchedule) {
                        if ($timingToken !== null) {
                            $this->issue($line, 'duplicate_timing', 'error', $token, 'duplicate_timing', span: $span);
                        } else {
                            $timingToken = $token;
                            $line->timing = 'any';
                            $line->timingCode = 'hs';
                        }
                        break;
                    }
                    if ($line->schedule !== null) {
                        $this->issue($line, 'duplicate_schedule', 'error', $token, 'duplicate_schedule', span: $span);
                        break;
                    }
                    $amount = $pending->data ?? ['value' => 1.0, 'unit' => null, 'text' => '1'];
                    $line->schedule = ['type' => 'frequency', 'code' => 'od', 'per_day' => 1, 'amount' => $amount['value'], '_amounts' => [$amount]];
                    $line->timing = 'any';
                    $line->timingCode = 'hs';
                    $timingToken = $token;
                    $this->hsAlone = true;
                    $scheduleText = ($pending !== null ? $pending->text.' ' : '').'hs';
                    $pending = null;
                    break;

                case 'duration':
                    if ($durationToken !== null) {
                        $this->issue($line, 'duplicate_duration', 'error', $token, 'duplicate_duration', span: $span);
                        break;
                    }
                    $durationToken = $token;
                    $line->duration = match ($token->data['kind']) {
                        'days' => ['type' => 'days', 'days' => (int) $token->data['days']],
                        'continuous' => ['type' => 'continuous', 'assumed_days' => $ctx->contDays ?? 30],
                        default => ['type' => 'till_finish'],
                    };
                    break;

                case 'timing':
                    if ($timingToken !== null) {
                        $this->issue($line, 'duplicate_timing', 'error', $token, 'duplicate_timing', span: $span);
                        break;
                    }
                    $timingToken = $token;
                    $line->timing = $token->data['timing'];
                    $line->timingCode = $token->data['code'];
                    break;

                case 'route':
                    if ($routeToken !== null) {
                        $this->issue($line, 'duplicate_route', 'error', $token, 'duplicate_route', span: $span);
                        break;
                    }
                    $routeToken = $token;
                    $line->routeCode = $token->data['code'];
                    if ($ctx?->formCode !== null) {
                        $allowed = Keywords::forms()[$ctx->formCode]['routes'] ?? null;
                        if ($allowed !== null && ! in_array($token->data['code'], $allowed, true)) {
                            $this->issue($line, 'route_incompatible', 'error', $token, 'route_incompatible', span: $span);
                        }
                    }
                    break;

                case 'qty':
                    if ($qty !== null) {
                        $this->issue($line, 'unknown_token', 'error', $token, 'duplicate_quantity', span: $span);
                        break;
                    }
                    $qty = $token;
                    if ($token->data['value'] <= 0) {
                        $this->issue($line, 'amount_zero', 'error', $token, 'amount_zero', span: $span);
                    }
                    break;

                default:
                    $suggestion = $token->data['suggestion'] ?? null;
                    $this->issue($line, 'unknown_token', 'error', $token, $suggestion !== null ? 'unknown_token_suggest' : 'unknown_token', ['{suggestion}' => (string) $suggestion], $span, $suggestion);
            }
        }

        if ($pending !== null) {
            $this->issue($line, 'unknown_token', 'error', $pending, 'amount_dangling', span: $this->spanOf($pending));
        }

        $this->resolveAmounts($line, $ctx, $scheduleText);

        if ($qty !== null) {
            $line->quantity = ['value' => $qty->data['value'], 'unit' => '', 'source' => 'override', 'basis' => null, '_pack' => $qty->data['pack']];
        }

        $line->normalized = $this->normalized($line, $body, $scheduleText, $durationToken, $timingToken, $routeToken, $qty);

        if ($line->hasErrors()) {
            $this->collapseToError($line, $ctx, $body);
        }

        return $line;
    }

    /** Units, mg → presentation units, unit inference, daily_total. */
    private function resolveAmounts(ParsedLine $line, ?ParseContext $ctx, ?string $scheduleText): void
    {
        $default = $ctx->defaultUnit ?? 'tab';
        $line->unit = $default;
        $line->unitInferred = false;

        if ($line->schedule === null) {
            $line->unitInferred = true;

            return;
        }

        $amounts = $line->schedule['_amounts'];
        unset($line->schedule['_amounts']);
        $mass = Keywords::massUnits();
        $liquidMl = Keywords::liquidMl();
        $values = [];
        $units = [];
        $this->unitTyped = array_filter($amounts, fn ($a) => $a['unit'] !== null && ! isset($mass[$a['unit']])) !== [];

        foreach ($amounts as $amount) {
            $value = (float) $amount['value'];
            $unit = $amount['unit'];

            if ($unit !== null && isset($mass[$unit])) {
                $mg = $value * $mass[$unit];
                $converted = $this->convertMass($line, $ctx, $mg, $scheduleText ?? $amount['text']);

                if ($converted === null) {
                    return;
                }

                [$value, $unit] = $converted;
            }

            $values[] = $value;
            $units[] = $unit;
        }

        $explicit = array_values(array_unique(array_filter($units, fn ($u) => $u !== null)));

        if ($explicit !== []) {
            $unit = $explicit[0];

            if (count($explicit) > 1) {
                $allLiquid = array_filter($explicit, fn ($u) => ! isset($liquidMl[$u])) === [];

                if (! $allLiquid) {
                    $this->issue($line, 'unit_mismatch', 'error', null, 'unit_mismatch_slots', ['{token}' => (string) $scheduleText], $this->spanOf($scheduleText));

                    return;
                }

                foreach ($values as $i => $v) {
                    if ($units[$i] !== null && $units[$i] !== $unit) {
                        $values[$i] = $v * $liquidMl[$units[$i]] / $liquidMl[$unit];
                    }
                }
            }

            $line->unit = $unit;
            $line->unitInferred = false;
        } else {
            $line->unit = $default;
            $line->unitInferred = true;
            $this->issue($line, 'unit_inferred', 'info', null, 'unit_inferred', ['{unit}' => $default]);
        }

        $s = &$line->schedule;

        switch ($s['type']) {
            case 'slots':
                $s['slots'] = $values;
                if (array_sum($values) <= 0 || min($values) < 0) {
                    $this->issue($line, 'amount_zero', 'error', null, 'amount_zero', ['{token}' => (string) $scheduleText], $this->spanOf($scheduleText));
                }
                $line->dailyTotal = array_sum($values);
                break;
            case 'frequency':
                $s['amount'] = $values[0];
                $line->dailyTotal = $values[0] * $s['per_day'];
                break;
            case 'interval':
                $s['amount'] = $values[0];
                $line->dailyTotal = $s['every_hours'] > 0 && 24 % $s['every_hours'] === 0 ? $values[0] * (24 / $s['every_hours']) : null;
                break;
            case 'stat':
                $s['amount'] = $values[0];
                $line->dailyTotal = null;
                break;
            case 'sos':
                $s['amount'] = $values[0];
                $line->dailyTotal = $s['max_per_day'] !== null ? $values[0] * $s['max_per_day'] : null;
                break;
        }

        if ($s['type'] !== 'slots' && $values[0] <= 0) {
            $this->issue($line, 'amount_zero', 'error', null, 'amount_zero', ['{token}' => (string) $scheduleText], $this->spanOf($scheduleText));
        }

        if (($s['type'] === 'sos' && $s['max_per_day'] !== null && $s['max_per_day'] <= 0)) {
            $this->issue($line, 'amount_zero', 'error', null, 'amount_zero', ['{token}' => 'max '.$s['max_per_day']]);
        }
    }

    /**
     * mg → presentation units (multiples of 1/4 on solids) or ml on liquids.
     *
     * @return array{0: float, 1: string}|null
     */
    private function convertMass(ParsedLine $line, ?ParseContext $ctx, float $mg, string $token): ?array
    {
        $mgText = NumberFormat::decimal($mg);

        if ($ctx === null || $ctx->strengthMg === null) {
            $this->issue($line, 'unit_mismatch', 'error', null, 'unit_mismatch_strength_unknown', ['{mg}' => $mgText], $this->spanOf($token));

            return null;
        }

        if ($ctx->isLiquid) {
            if ($ctx->perMl === null || $ctx->perMl <= 0) {
                $this->issue($line, 'unit_mismatch', 'error', null, 'unit_mismatch_strength_unknown', ['{mg}' => $mgText], $this->spanOf($token));

                return null;
            }

            return [round($mg / $ctx->perMl, 2), 'ml'];
        }

        $units = $mg / $ctx->strengthMg;
        $quarters = $units * 4;

        if (abs($quarters - round($quarters)) > 1e-6 || $units <= 0) {
            $this->issue($line, 'unit_mismatch', 'error', null, 'unit_mismatch_mg', [
                '{strength}' => $ctx->strengthLabel ?? NumberFormat::decimal($ctx->strengthMg).' mg',
                '{form}' => $ctx->formLabel ?? 'tablet',
                '{mg}' => $mgText,
            ], $this->spanOf($token));

            return null;
        }

        return [round($quarters) / 4, $ctx->defaultUnit];
    }

    /** Canonical spelling: schedule · duration · timing · route · qty · // instruction. */
    private function normalized(ParsedLine $line, string $body, ?string $scheduleText, ?Token $duration, ?Token $timing, ?Token $route, ?Token $qty): string
    {
        $parts = [];

        if ($line->schedule !== null) {
            $parts[] = $this->canonicalSchedule($line);
        }

        if ($duration !== null) {
            $parts[] = match ($line->duration['type'] ?? null) {
                'days' => $line->duration['days'].'d',
                'continuous' => 'cont',
                'till_finish' => 'tf',
                default => $duration->text,
            };
        }

        $bareHs = $this->hsAlone && (float) ($line->schedule['amount'] ?? 0) === 1.0 && ! $this->unitTyped;

        if ($line->timingCode !== null && ! $bareHs) {
            $parts[] = $line->timingCode;
        }

        if ($line->routeCode !== null) {
            $parts[] = $line->routeCode;
        }

        if ($qty !== null) {
            $parts[] = 'x'.$qty->data['value'].($qty->data['pack'] !== null ? ' '.$qty->data['pack'] : '');
        }

        $text = implode(' ', array_filter($parts, fn ($p) => $p !== ''));

        if ($text === '' && $line->schedule === null && $body !== '') {
            $text = $body;
        }

        if ($line->instruction !== null) {
            $text = trim($text.' // '.$line->instruction);
        }

        return $text;
    }

    private function canonicalSchedule(ParsedLine $line): string
    {
        $s = $line->schedule;
        $attached = in_array($line->unit, Keywords::attachedUnits(), true);
        $showUnit = $this->unitTyped || (! $line->unitInferred && $line->unit !== $this->defaultUnit);   // typed, or mg→ml on a liquid
        $unit = $showUnit ? ($attached ? '' : ' ').$line->unit : '';
        $amount = fn (float $v) => NumberFormat::amount($v).($v > 0 ? $unit : '');

        return match ($s['type']) {
            'slots' => implode('+', array_map(fn ($v) => NumberFormat::amount((float) $v).((float) $v > 0 && $unit !== '' ? $unit : ''), $s['slots'])),
            'frequency' => $this->hsAlone && (float) $s['amount'] === 1.0 && ! $this->unitTyped ? 'hs' : $amount((float) $s['amount']).' '.$s['code'],
            'interval' => $amount((float) $s['amount']).' q'.$s['every_hours'].'h',
            'stat' => ((float) $s['amount'] === 1.0 && ! $this->unitTyped ? '' : $amount((float) $s['amount']).' ').'stat',
            'sos' => $amount((float) $s['amount']).' sos'.($s['max_per_day'] !== null ? ' max '.$s['max_per_day'] : ''),
            default => '',
        };
    }

    /** Errors block the line: keep only the errors, clear every interpretation (PRESCRIPTION.md §2.13). */
    private function collapseToError(ParsedLine $line, ?ParseContext $ctx, string $body): void
    {
        $line->issues = $line->errors();
        $line->schedule = null;
        $line->dailyTotal = null;
        $line->duration = null;
        $line->timing = 'any';
        $line->timingCode = null;
        $line->routeCode = null;
        $line->unit = $ctx->defaultUnit ?? 'tab';
        $line->unitInferred = true;
        $line->quantity = ['value' => null, 'unit' => $line->unit, 'source' => 'none', 'basis' => null];
        $line->normalized = trim($body.($line->instruction !== null ? ' // '.$line->instruction : ''));
    }

    /**
     * @param  array<string, string>  $params
     * @param  array{0: int, 1: int}|null  $span
     */
    private function issue(ParsedLine $line, string $code, string $severity, Token|string|null $token, string $messageKey, array $params = [], ?array $span = null, ?string $suggestion = null): void
    {
        $text = $token instanceof Token ? $token->text : $token;
        $params += ['{token}' => (string) $text];
        $m = Keywords::message($messageKey);
        $line->issues[] = new ParseIssue($code, $severity, $text, $span, strtr($m['en'], $params), strtr($m['bn'], $params), $suggestion);
    }

    /**
     * Char span of a token in `raw` (case-insensitive, searched left to right); null when normalisation moved it.
     *
     * @return array{0: int, 1: int}|null
     */
    private function span(string $text): ?array
    {
        $pos = mb_stripos($this->raw, $text, $this->spanCursor);

        if ($pos === false) {
            return null;
        }

        $this->spanCursor = $pos + mb_strlen($text);

        return [$pos, $this->spanCursor];
    }

    /** @return array{0: int, 1: int}|null */
    private function spanOf(Token|string|null $token): ?array
    {
        $text = $token instanceof Token ? $token->text : $token;

        if ($text === null || $text === '') {
            return null;
        }

        $pos = mb_stripos($this->raw, $text);

        return $pos === false ? null : [$pos, $pos + mb_strlen($text)];
    }
}
