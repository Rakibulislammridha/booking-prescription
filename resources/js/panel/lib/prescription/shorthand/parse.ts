// parseLine(text, ctx) — the client mirror of App\Domain\Prescription\Shorthand\ShorthandParser (PRESCRIPTION.md
// §2.15). Pure, deterministic, no I/O: normalize → tokenize → classify → assemble → quantity. The server re-parse
// stays authoritative; this exists for instant feedback. Parity is proven by running the SAME fixture
// (tests/Fixtures/shorthand_cases.json) through both implementations.
import type { ParseContext, ParsedLine } from '@shared/types/models';
import { Assembler, type WorkingLine } from './assemble';
import { normalize } from './normalize';
import { computeQuantity } from './quantity';
import { tokenize } from './tokenize';

export function toDoseJson(line: WorkingLine): ParsedLine {
  return {
    v: 1,
    raw: line.raw,
    normalized: line.normalized,
    unit: line.unit as ParsedLine['unit'],
    unit_inferred: line.unitInferred,
    schedule: (line.schedule as ParsedLine['schedule']) ?? null,
    daily_total: line.dailyTotal,
    duration: (line.duration as ParsedLine['duration']) ?? null,
    timing: line.timing as ParsedLine['timing'],
    timing_code: line.timingCode as ParsedLine['timing_code'],
    route_code: line.routeCode,
    quantity: { value: line.quantity.value, unit: line.quantity.unit, source: line.quantity.source, basis: line.quantity.basis },
    instruction: line.instruction,
    issues: line.issues,
  };
}

export function parseLine(text: string, ctx: ParseContext | null): ParsedLine {
  const n = normalize(text);
  const tokens = tokenize(n.body);
  const line = new Assembler().assemble(n.raw, n.body, n.instruction, tokens, ctx);
  computeQuantity(line, ctx);
  return toDoseJson(line);
}

export function lineHasErrors(line: ParsedLine | null): boolean {
  return line !== null && line.issues.some((i) => i.severity === 'error');
}

export function lineErrors(line: ParsedLine | null): ParsedLine['issues'] {
  return line === null ? [] : line.issues.filter((i) => i.severity === 'error');
}

export function lineWarnings(line: ParsedLine | null): ParsedLine['issues'] {
  return line === null ? [] : line.issues.filter((i) => i.severity === 'warning');
}
