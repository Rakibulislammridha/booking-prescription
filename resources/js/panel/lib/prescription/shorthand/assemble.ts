// Turns the token stream into a ParsedLine (PRESCRIPTION.md §2.4–§2.10) — mirrors App\Domain\Prescription\
// Shorthand\Assembler: one schedule, one duration, one timing, one route, one quantity override; unit inference
// and mg→unit conversion against the ParseContext; the never-guess-silently issue policy (§2.13). Quantity is
// filled afterwards by ./quantity.
import type { ParseContext, ParseIssue } from '@shared/types/models';
import { attachedUnits, forms, liquidMl, massUnits, message } from './keywords';
import { amount as spellAmount, decimal, phpRound } from './numberFormat';
import type { AmountData, Token } from './tokenize';

export interface WorkingSchedule {
  type: 'slots' | 'frequency' | 'interval' | 'stat' | 'sos';
  slots?: number[];
  code?: string;
  per_day?: number;
  every_hours?: number;
  amount?: number;
  max_per_day?: number | null;
  _amounts?: AmountData[];
}

export interface WorkingQuantity {
  value: number | null;
  unit: string;
  source: 'auto' | 'override' | 'none';
  basis: string | null;
  _pack?: string | null;
}

export interface WorkingLine {
  raw: string;
  normalized: string;
  unit: string;
  unitInferred: boolean;
  schedule: WorkingSchedule | null;
  dailyTotal: number | null;
  duration: { type: 'days'; days: number } | { type: 'continuous'; assumed_days: number } | { type: 'till_finish' } | null;
  timing: string;
  timingCode: string | null;
  routeCode: string | null;
  quantity: WorkingQuantity;
  instruction: string | null;
  issues: ParseIssue[];
}

export function newLine(raw: string): WorkingLine {
  return {
    raw,
    normalized: '',
    unit: 'tab',
    unitInferred: false,
    schedule: null,
    dailyTotal: null,
    duration: null,
    timing: 'any',
    timingCode: null,
    routeCode: null,
    quantity: { value: null, unit: 'tab', source: 'none', basis: null },
    instruction: null,
    issues: [],
  };
}

export function hasErrors(line: WorkingLine): boolean {
  return line.issues.some((i) => i.severity === 'error');
}

export function effectiveDays(line: WorkingLine): number | null {
  if (line.duration === null) return null;
  if (line.duration.type === 'days') return line.duration.days;
  if (line.duration.type === 'continuous') return line.duration.assumed_days;
  return null;
}

const SCHEDULE_TYPES = new Set(['slots', 'freq', 'interval', 'stat', 'sos']);

export class Assembler {
  private raw = '';
  private spanCursor = 0;
  private hsAlone = false;
  /** The doctor typed a unit word (tsp, drop, unit…); mg/g/mcg conversions do not count. */
  private unitTyped = false;
  private defaultUnit = 'tab';

  assemble(raw: string, body: string, instruction: string | null, tokens: Token[], ctx: ParseContext | null): WorkingLine {
    this.raw = raw;
    this.spanCursor = 0;
    this.hsAlone = false;
    this.unitTyped = false;
    this.defaultUnit = ctx?.default_unit ?? 'tab';

    const line = newLine(raw);
    line.instruction = instruction;

    if (instruction !== null && instruction.length > 200) this.issue(line, 'instruction_too_long', 'error', null, 'instruction_too_long');
    if (ctx === null) this.issue(line, 'drug_missing', 'error', null, 'drug_missing');

    const hasSchedule = tokens.some((t) => SCHEDULE_TYPES.has(t.type));
    let pending: Token | null = null;
    let qty: Token | null = null;
    let scheduleText: string | null = null;
    let durationToken: Token | null = null;
    let timingToken: Token | null = null;
    let routeToken: Token | null = null;

    for (const token of tokens) {
      const span = this.span(token.text);

      switch (token.type) {
        case 'amount': {
          if (pending !== null) this.issue(line, 'unknown_token', 'error', pending, 'amount_dangling', {}, this.spanOf(pending));
          pending = token;
          break;
        }

        case 'slots': {
          if (line.schedule !== null) {
            this.issue(line, 'duplicate_schedule', 'error', token, 'duplicate_schedule', {}, span);
            break;
          }
          const amounts = token.data.amounts as AmountData[];
          if (amounts.length > 4) {
            this.issue(line, 'slot_count', 'error', token, 'slot_count', {}, span);
            break;
          }
          line.schedule = { type: 'slots', slots: amounts.map((a) => a.value), _amounts: amounts };
          scheduleText = token.text;
          break;
        }

        case 'freq':
        case 'interval':
        case 'stat':
        case 'sos': {
          if (token.type === 'sos' && line.schedule?.type === 'frequency' && pending === null) {
            line.schedule = { type: 'sos', amount: line.schedule.amount, max_per_day: line.schedule.per_day ?? null, _amounts: line.schedule._amounts };
            scheduleText = `${scheduleText ?? ''} sos`;
            break;
          }
          if (line.schedule !== null) {
            this.issue(line, 'duplicate_schedule', 'error', token, 'duplicate_schedule', {}, span);
            pending = null;
            break;
          }
          const amt: AmountData = (pending?.data as AmountData | undefined) ?? { value: 1, unit: null, text: '1' };
          if (token.type === 'freq') line.schedule = { type: 'frequency', code: String(token.data.code), per_day: Number(token.data.per_day), amount: amt.value };
          else if (token.type === 'interval') line.schedule = { type: 'interval', every_hours: Number(token.data.hours), amount: amt.value };
          else if (token.type === 'stat') line.schedule = { type: 'stat', amount: amt.value };
          else line.schedule = { type: 'sos', amount: amt.value, max_per_day: null };
          line.schedule._amounts = [amt];
          if (token.type === 'interval') {
            const hours = Number(token.data.hours);
            if (hours <= 0 || 24 % hours !== 0) this.issue(line, 'interval_invalid', 'error', token, 'interval_invalid', {}, span);
          }
          scheduleText = `${pending !== null ? `${pending.text} ` : ''}${token.text}`;
          pending = null;
          break;
        }

        case 'max': {
          if (line.schedule?.type === 'sos') {
            line.schedule.max_per_day = Number(token.data.value);
            scheduleText = `${scheduleText ?? ''} max ${String(token.data.value)}`;
          } else {
            this.issue(line, 'max_without_sos', 'error', token, 'max_without_sos', {}, span);
          }
          break;
        }

        case 'hs': {
          if (hasSchedule) {
            if (timingToken !== null) {
              this.issue(line, 'duplicate_timing', 'error', token, 'duplicate_timing', {}, span);
            } else {
              timingToken = token;
              line.timing = 'any';
              line.timingCode = 'hs';
            }
            break;
          }
          if (line.schedule !== null) {
            this.issue(line, 'duplicate_schedule', 'error', token, 'duplicate_schedule', {}, span);
            break;
          }
          const amt: AmountData = (pending?.data as AmountData | undefined) ?? { value: 1, unit: null, text: '1' };
          line.schedule = { type: 'frequency', code: 'od', per_day: 1, amount: amt.value, _amounts: [amt] };
          line.timing = 'any';
          line.timingCode = 'hs';
          timingToken = token;
          this.hsAlone = true;
          scheduleText = `${pending !== null ? `${pending.text} ` : ''}hs`;
          pending = null;
          break;
        }

        case 'duration': {
          if (durationToken !== null) {
            this.issue(line, 'duplicate_duration', 'error', token, 'duplicate_duration', {}, span);
            break;
          }
          durationToken = token;
          const kind = String(token.data.kind);
          if (kind === 'days') line.duration = { type: 'days', days: Number(token.data.days) };
          else if (kind === 'continuous') line.duration = { type: 'continuous', assumed_days: ctx?.cont_days ?? 30 };
          else line.duration = { type: 'till_finish' };
          break;
        }

        case 'timing': {
          if (timingToken !== null) {
            this.issue(line, 'duplicate_timing', 'error', token, 'duplicate_timing', {}, span);
            break;
          }
          timingToken = token;
          line.timing = String(token.data.timing);
          line.timingCode = String(token.data.code);
          break;
        }

        case 'route': {
          if (routeToken !== null) {
            this.issue(line, 'duplicate_route', 'error', token, 'duplicate_route', {}, span);
            break;
          }
          routeToken = token;
          line.routeCode = String(token.data.code);
          if (ctx != null && ctx.form_code != null) {
            const allowed = forms()[ctx.form_code]?.routes ?? null;
            if (allowed !== null && !allowed.includes(String(token.data.code))) this.issue(line, 'route_incompatible', 'error', token, 'route_incompatible', {}, span);
          }
          break;
        }

        case 'qty': {
          if (qty !== null) {
            this.issue(line, 'unknown_token', 'error', token, 'duplicate_quantity', {}, span);
            break;
          }
          qty = token;
          if (Number(token.data.value) <= 0) this.issue(line, 'amount_zero', 'error', token, 'amount_zero', {}, span);
          break;
        }

        default: {
          const suggestion = (token.data.suggestion as string | null | undefined) ?? null;
          this.issue(line, 'unknown_token', 'error', token, suggestion !== null ? 'unknown_token_suggest' : 'unknown_token', { '{suggestion}': String(suggestion) }, span, suggestion);
        }
      }
    }

    if (pending !== null) this.issue(line, 'unknown_token', 'error', pending, 'amount_dangling', {}, this.spanOf(pending));

    this.resolveAmounts(line, ctx, scheduleText);

    if (qty !== null) {
      line.quantity = { value: Number(qty.data.value), unit: '', source: 'override', basis: null, _pack: (qty.data.pack as string | null) ?? null };
    }

    line.normalized = this.normalized(line, body, scheduleText, durationToken, qty);

    if (hasErrors(line)) this.collapseToError(line, ctx, body);

    return line;
  }

  /** Units, mg → presentation units, unit inference, daily_total. */
  private resolveAmounts(line: WorkingLine, ctx: ParseContext | null, scheduleText: string | null): void {
    const fallback = ctx?.default_unit ?? 'tab';
    line.unit = fallback;
    line.unitInferred = false;

    if (line.schedule === null) {
      line.unitInferred = true;
      return;
    }

    const amounts = line.schedule._amounts ?? [];
    delete line.schedule._amounts;
    const mass = massUnits();
    const ml = liquidMl();
    const values: number[] = [];
    const units: Array<string | null> = [];
    this.unitTyped = amounts.some((a) => a.unit !== null && mass[a.unit] === undefined);

    for (const entry of amounts) {
      let value = entry.value;
      let unit = entry.unit;

      if (unit !== null && mass[unit] !== undefined) {
        const mg = value * (mass[unit] as number);
        const converted = this.convertMass(line, ctx, mg, scheduleText ?? entry.text);
        if (converted === null) return;
        [value, unit] = converted;
      }

      values.push(value);
      units.push(unit);
    }

    const explicit = [...new Set(units.filter((u): u is string => u !== null && u !== ''))];

    if (explicit.length > 0) {
      const unit = explicit[0] as string;

      if (explicit.length > 1) {
        const allLiquid = explicit.every((u) => ml[u] !== undefined);
        if (!allLiquid) {
          this.issue(line, 'unit_mismatch', 'error', null, 'unit_mismatch_slots', { '{token}': String(scheduleText ?? '') }, this.spanOf(scheduleText));
          return;
        }
        for (let i = 0; i < values.length; i++) {
          const u = units[i];
          if (u !== null && u !== undefined && u !== unit) values[i] = (values[i] as number) * (ml[u] as number) / (ml[unit] as number);
        }
      }

      line.unit = unit;
      line.unitInferred = false;
    } else {
      line.unit = fallback;
      line.unitInferred = true;
      this.issue(line, 'unit_inferred', 'info', null, 'unit_inferred', { '{unit}': fallback });
    }

    const s = line.schedule;
    const first = values[0] ?? 0;

    switch (s.type) {
      case 'slots': {
        s.slots = values;
        const sum = values.reduce((a, b) => a + b, 0);
        if (sum <= 0 || Math.min(...values) < 0) {
          this.issue(line, 'amount_zero', 'error', null, 'amount_zero', { '{token}': String(scheduleText ?? '') }, this.spanOf(scheduleText));
        }
        line.dailyTotal = sum;
        break;
      }
      case 'frequency':
        s.amount = first;
        line.dailyTotal = first * (s.per_day ?? 0);
        break;
      case 'interval': {
        s.amount = first;
        const hours = s.every_hours ?? 0;
        line.dailyTotal = hours > 0 && 24 % hours === 0 ? first * (24 / hours) : null;
        break;
      }
      case 'stat':
        s.amount = first;
        line.dailyTotal = null;
        break;
      case 'sos':
        s.amount = first;
        line.dailyTotal = s.max_per_day != null ? first * s.max_per_day : null;
        break;
    }

    if (s.type !== 'slots' && first <= 0) {
      this.issue(line, 'amount_zero', 'error', null, 'amount_zero', { '{token}': String(scheduleText ?? '') }, this.spanOf(scheduleText));
    }

    if (s.type === 'sos' && s.max_per_day != null && s.max_per_day <= 0) {
      this.issue(line, 'amount_zero', 'error', null, 'amount_zero', { '{token}': `max ${s.max_per_day}` });
    }
  }

  /** mg → presentation units (multiples of 1/4 on solids) or ml on liquids. */
  private convertMass(line: WorkingLine, ctx: ParseContext | null, mg: number, token: string): [number, string] | null {
    const mgText = decimal(mg);

    if (ctx === null || ctx.strength_mg === null) {
      this.issue(line, 'unit_mismatch', 'error', null, 'unit_mismatch_strength_unknown', { '{mg}': mgText }, this.spanOf(token));
      return null;
    }

    if (ctx.is_liquid) {
      if (ctx.per_ml === null || ctx.per_ml <= 0) {
        this.issue(line, 'unit_mismatch', 'error', null, 'unit_mismatch_strength_unknown', { '{mg}': mgText }, this.spanOf(token));
        return null;
      }
      return [phpRound(mg / ctx.per_ml, 2), 'ml'];
    }

    const units = mg / ctx.strength_mg;
    const quarters = units * 4;

    if (Math.abs(quarters - phpRound(quarters)) > 1e-6 || units <= 0) {
      this.issue(line, 'unit_mismatch', 'error', null, 'unit_mismatch_mg', {
        '{strength}': ctx.strength_label ?? `${decimal(ctx.strength_mg)} mg`,
        '{form}': ctx.form_label ?? 'tablet',
        '{mg}': mgText,
      }, this.spanOf(token));
      return null;
    }

    return [phpRound(quarters) / 4, ctx.default_unit];
  }

  /** Canonical spelling: schedule · duration · timing · route · qty · // instruction. */
  private normalized(line: WorkingLine, body: string, scheduleText: string | null, duration: Token | null, qty: Token | null): string {
    const parts: string[] = [];

    if (line.schedule !== null) parts.push(this.canonicalSchedule(line));

    if (duration !== null) {
      const d = line.duration;
      if (d?.type === 'days') parts.push(`${d.days}d`);
      else if (d?.type === 'continuous') parts.push('cont');
      else if (d?.type === 'till_finish') parts.push('tf');
      else parts.push(duration.text);
    }

    const bareHs = this.hsAlone && Number(line.schedule?.amount ?? 0) === 1 && !this.unitTyped;

    if (line.timingCode !== null && !bareHs) parts.push(line.timingCode);
    if (line.routeCode !== null) parts.push(line.routeCode);
    if (qty !== null) parts.push(`x${String(qty.data.value)}${qty.data.pack != null ? ` ${String(qty.data.pack)}` : ''}`);

    let text = parts.filter((p) => p !== '').join(' ');
    if (text === '' && line.schedule === null && body !== '') text = body;
    if (line.instruction !== null) text = `${text} // ${line.instruction}`.trim();

    return text;
  }

  private canonicalSchedule(line: WorkingLine): string {
    const s = line.schedule;
    if (s === null) return '';
    const attached = attachedUnits().includes(line.unit);
    const showUnit = this.unitTyped || (!line.unitInferred && line.unit !== this.defaultUnit); // typed, or mg→ml on a liquid
    const unit = showUnit ? `${attached ? '' : ' '}${line.unit}` : '';
    const amount = (v: number): string => spellAmount(v) + (v > 0 ? unit : '');

    switch (s.type) {
      case 'slots':
        return (s.slots ?? []).map((v) => spellAmount(v) + (v > 0 && unit !== '' ? unit : '')).join('+');
      case 'frequency':
        return this.hsAlone && Number(s.amount) === 1 && !this.unitTyped ? 'hs' : `${amount(Number(s.amount))} ${String(s.code)}`;
      case 'interval':
        return `${amount(Number(s.amount))} q${String(s.every_hours)}h`;
      case 'stat':
        return `${Number(s.amount) === 1 && !this.unitTyped ? '' : `${amount(Number(s.amount))} `}stat`;
      case 'sos':
        return `${amount(Number(s.amount))} sos${s.max_per_day != null ? ` max ${s.max_per_day}` : ''}`;
      default:
        return '';
    }
  }

  /** Errors block the line: keep only the errors, clear every interpretation (PRESCRIPTION.md §2.13). */
  private collapseToError(line: WorkingLine, ctx: ParseContext | null, body: string): void {
    line.issues = line.issues.filter((i) => i.severity === 'error');
    line.schedule = null;
    line.dailyTotal = null;
    line.duration = null;
    line.timing = 'any';
    line.timingCode = null;
    line.routeCode = null;
    line.unit = ctx?.default_unit ?? 'tab';
    line.unitInferred = true;
    line.quantity = { value: null, unit: line.unit, source: 'none', basis: null };
    line.normalized = `${body}${line.instruction !== null ? ` // ${line.instruction}` : ''}`.trim();
  }

  private issue(
    line: WorkingLine,
    code: ParseIssue['code'],
    severity: ParseIssue['severity'],
    token: Token | string | null,
    messageKey: string,
    params: Record<string, string> = {},
    span: [number, number] | null = null,
    suggestion: string | null = null,
  ): void {
    const text = typeof token === 'string' ? token : token?.text ?? null;
    const all: Record<string, string> = { ...params };
    if (all['{token}'] === undefined) all['{token}'] = String(text ?? '');
    const m = message(messageKey);
    line.issues.push({
      code,
      severity,
      token: text,
      span,
      message: strtr(m.en, all),
      message_bn: strtr(m.bn, all),
      suggestion,
    });
  }

  /** Char span of a token in `raw` (case-insensitive, left to right); null when normalisation moved it. */
  private span(text: string): [number, number] | null {
    const pos = this.raw.toLowerCase().indexOf(text.toLowerCase(), this.spanCursor);
    if (pos === -1) return null;
    this.spanCursor = pos + text.length;
    return [pos, this.spanCursor];
  }

  private spanOf(token: Token | string | null): [number, number] | null {
    const text = typeof token === 'string' ? token : token?.text ?? null;
    if (text === null || text === '') return null;
    const pos = this.raw.toLowerCase().indexOf(text.toLowerCase());
    return pos === -1 ? null : [pos, pos + text.length];
  }
}

/** PHP strtr(): longest key first, no re-scan of replaced text. */
export function strtr(subject: string, replacements: Record<string, string>): string {
  const keys = Object.keys(replacements).filter((k) => k !== '').sort((a, b) => b.length - a.length);
  if (keys.length === 0) return subject;
  let out = '';
  let i = 0;
  outer: while (i < subject.length) {
    for (const key of keys) {
      if (subject.startsWith(key, i)) {
        out += replacements[key] ?? '';
        i += key.length;
        continue outer;
      }
    }
    out += subject[i];
    i++;
  }
  return out;
}
