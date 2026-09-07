// PRESCRIPTION.md §2.12 — mirrors App\Domain\Prescription\Shorthand\QuantityCalculator: quantity to dispense per
// counting family (counted · liquid · insulin · inhaler · packs), rounding up, pack sizes, `x` overrides, cont/tf.
// Also emits missing_duration / missing_schedule / quantity_unknown / continuous_assumed per §2.5, §2.9, §2.13.
import type { ParseContext, ParseIssue } from '@shared/types/models';
import { strtr, effectiveDays, hasErrors, type WorkingLine, type WorkingQuantity } from './assemble';
import { defaultPackSize, familyOf, forms, liquidMl, message, packDays } from './keywords';
import { ceilSlack, decimal, phpRound } from './numberFormat';

const NO_CONTEXT: ParseContext = {
  form_code: null,
  default_unit: 'tab',
  pack_size: null,
  pack_unit: null,
  strength_mg: null,
  per_ml: null,
  is_liquid: false,
  cont_days: 30,
  locale: 'en',
  route_code: null,
  strength_label: null,
  form_label: null,
};

function num(v: number): number {
  return Math.abs(v - phpRound(v)) < 1e-9 ? phpRound(v) : phpRound(v, 2);
}

function defaultPackUnit(family: string, unit: string): string {
  switch (family) {
    case 'liquid':
      return 'bottle';
    case 'insulin':
      return 'vial';
    case 'inhaler':
      return 'inhaler';
    case 'packs':
      return unit === 'app' ? 'tube' : 'bottle';
    default:
      return unit;
  }
}

function issue(line: WorkingLine, code: ParseIssue['code'], severity: ParseIssue['severity'], messageKey: string, params: Record<string, string> = {}): void {
  const m = message(messageKey);
  line.issues.push({ code, severity, token: null, span: null, message: strtr(m.en, params), message_bn: strtr(m.bn, params), suggestion: null });
}

function applyOverride(line: WorkingLine, override: WorkingQuantity, family: string, unit: string, packUnit: string): void {
  const pack = override._pack ?? null;
  const dispenseUnit = pack ?? (family === 'counted' ? unit : packUnit);
  line.quantity = { value: Math.trunc(override.value ?? 0), unit: dispenseUnit, source: 'override', basis: 'override' };
}

export function computeQuantity(line: WorkingLine, context: ParseContext | null): void {
  if (hasErrors(line)) return;

  const ctx = context ?? NO_CONTEXT;
  const unit = line.unit;
  const family = familyOf(unit);
  const packUnit = ctx.pack_unit ?? forms()[ctx.form_code ?? '']?.pack_unit ?? defaultPackUnit(family, unit);
  const override = line.quantity.source === 'override' ? line.quantity : null;
  const days = effectiveDays(line);
  const schedule = line.schedule;
  const isPackForm = family === 'packs' || family === 'inhaler';

  if (line.duration?.type === 'continuous') issue(line, 'continuous_assumed', 'info', 'continuous_assumed', { '{days}': String(line.duration.assumed_days) });

  if (schedule === null) {
    if (override !== null) return applyOverride(line, override, family, unit, packUnit);
    if (isPackForm) {
      line.quantity = { value: 1, unit: packUnit, source: 'auto', basis: `1 ${packUnit}` };
      return;
    }
    issue(line, 'missing_schedule', 'warning', 'missing_schedule');
    line.quantity = { value: null, unit, source: 'none', basis: null };
    return;
  }

  const type = schedule.type;

  if (override !== null) return applyOverride(line, override, family, unit, packUnit);

  if (type === 'stat') {
    const amount = Number(schedule.amount);
    if (family === 'counted') {
      line.quantity = { value: num(Math.ceil(amount)), unit, source: 'auto', basis: `stat = ${decimal(Math.ceil(amount))} ${unit}` };
    } else if (family === 'liquid') {
      const ml = amount * (liquidMl()[unit] ?? 1);
      line.quantity = { value: num(ml), unit: 'ml', source: 'auto', basis: `stat = ${decimal(ml)} ml` };
    } else {
      line.quantity = { value: 1, unit: packUnit, source: 'auto', basis: `1 ${packUnit}` };
    }
    return;
  }

  if (type === 'sos' && schedule.max_per_day == null) {
    if (isPackForm) {
      line.quantity = { value: 1, unit: packUnit, source: 'auto', basis: `1 ${packUnit}` };
      return;
    }
    issue(line, 'quantity_unknown', 'warning', 'quantity_unknown');
    line.quantity = { value: null, unit, source: 'none', basis: null };
    return;
  }

  if (line.duration?.type === 'till_finish') {
    if (isPackForm) {
      line.quantity = { value: 1, unit: packUnit, source: 'auto', basis: `1 ${packUnit}` };
      return;
    }
    issue(line, 'quantity_unknown', 'warning', 'quantity_unknown');
    line.quantity = { value: null, unit, source: 'none', basis: null };
    return;
  }

  if (days === null && !isPackForm) {
    issue(line, 'missing_duration', 'warning', 'missing_duration');
    line.quantity = { value: null, unit, source: 'none', basis: null };
    return;
  }

  const daily = line.dailyTotal ?? 0;

  switch (family) {
    case 'counted': {
      const total = daily * (days ?? 0);
      const value = ceilSlack(total);
      const basis = `${decimal(daily)}/day × ${days} d = ${Math.abs(total - value) > 1e-9 ? `${decimal(total)} → ` : ''}${value} ${unit}`;
      line.quantity = { value, unit, source: 'auto', basis };
      break;
    }

    case 'liquid': {
      const mlPerDay = daily * (liquidMl()[unit] ?? 1);
      const totalMl = mlPerDay * (days ?? 0);
      if (ctx.pack_size !== null && ctx.pack_size > 0) {
        const bottles = ceilSlack(totalMl / ctx.pack_size);
        line.quantity = {
          value: bottles,
          unit: packUnit,
          source: 'auto',
          basis: `${decimal(mlPerDay)} ml/day × ${days} d = ${decimal(totalMl)} ml → ${bottles} × ${decimal(ctx.pack_size)} ml`,
        };
      } else {
        issue(line, 'quantity_unknown', 'info', 'quantity_pack_unknown');
        line.quantity = { value: num(totalMl), unit: 'ml', source: 'auto', basis: `${decimal(mlPerDay)} ml/day × ${days} d = ${decimal(totalMl)} ml` };
      }
      break;
    }

    case 'insulin': {
      const total = daily * (days ?? 0);
      const pack = ctx.pack_size ?? defaultPackSize('insulin') ?? 1000;
      const packs = ceilSlack(total / pack);
      line.quantity = { value: packs, unit: packUnit, source: 'auto', basis: `${decimal(daily)} unit/day × ${days} d = ${decimal(total)} unit → ${packs} ${packUnit}` };
      break;
    }

    case 'inhaler': {
      if (days === null) {
        line.quantity = { value: 1, unit: packUnit, source: 'auto', basis: `1 ${packUnit}` };
        break;
      }
      const total = daily * days;
      const pack = ctx.pack_size ?? defaultPackSize('inhaler') ?? 200;
      const packs = ceilSlack(total / pack);
      line.quantity = { value: packs, unit: packUnit, source: 'auto', basis: `${decimal(daily)} puff/day × ${days} d = ${decimal(total)} puff → ${packs} ${packUnit}` };
      break;
    }

    default: {
      // packs: drop / spray / app
      const packs = days === null ? 1 : Math.max(1, ceilSlack(days / packDays()));
      line.quantity = { value: packs, unit: packUnit, source: 'auto', basis: days === null ? `1 ${packUnit}` : `${packs} ${packUnit} (${days} d)` };
    }
  }
}
