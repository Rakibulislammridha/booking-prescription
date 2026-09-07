// The cross-implementation contract (PRESCRIPTION.md §2.16, §9.2): every row of tests/Fixtures/shorthand_cases.json
// — THE SAME FILE the PHP ShorthandParserTest runs — must produce a byte-identical dose_json here. If this file and
// its PHP twin are both green, the two parsers agree.
import { describe, expect, it } from 'vitest';
import fixture from '../../../../../../../tests/Fixtures/shorthand_cases.json';
import type { ParseContext, ParsedLine } from '@shared/types/models';
import { KEYWORDS } from '../keywords';
import { normalize } from '../normalize';
import { parseLine } from '../parse';

interface Case {
  id: string;
  input: string;
  ctx: string;
  expect: Record<string, unknown>;
  dose_json: ParsedLine;
}
interface Fixture {
  keywords_version: number;
  contexts: Record<string, ParseContext>;
  cases: Case[];
}

const data = fixture as unknown as Fixture;

function context(name: string): ParseContext | null {
  return data.contexts[name] ?? null;
}

describe('shorthand parser — PHP parity fixture', () => {
  it('runs the same keywords.json version the fixture was generated from', () => {
    expect(KEYWORDS.version).toBe(data.keywords_version);
  });

  it('covers every printed §2.16 row and at least 47 cases', () => {
    const ids = data.cases.map((c) => c.id);
    expect(ids.length).toBeGreaterThanOrEqual(47);
    for (const row of [...Array.from({ length: 46 }, (_, i) => String(i + 1)), '62']) expect(ids).toContain(row);
  });

  it.each(data.cases.map((c) => [`${c.id} \`${c.input}\` [${c.ctx}]`, c] as const))('%s', (_name, testCase) => {
    const got = parseLine(testCase.input, context(testCase.ctx));
    const want = testCase.expect;

    if ('has_errors' in want) {
      expect(got.issues.some((i) => i.severity === 'error')).toBe(want.has_errors);
    }

    for (const [key, value] of Object.entries(want)) {
      if (key === 'has_errors') continue;
      if (key === 'issues') {
        const summary = got.issues.map((i) => ({ severity: i.severity, code: i.code, ...(i.suggestion !== null ? { suggestion: i.suggestion } : {}) }));
        expect(summary).toEqual(value);
        continue;
      }
      if (key === 'quantity') {
        expect({ value: got.quantity.value, unit: got.quantity.unit, source: got.quantity.source }).toEqual(value);
        continue;
      }
      expect(got[key as keyof ParsedLine]).toEqual(value);
    }

    // The full ParsedLine is the contract PHP and TS both satisfy.
    expect(got).toEqual(testCase.dose_json);
    expect(got.v).toBe(1);
  });

  it('is idempotent on `normalized` for every clean case', () => {
    for (const testCase of data.cases) {
      const ctx = context(testCase.ctx);
      const first = parseLine(testCase.input, ctx);
      if (first.issues.some((i) => i.severity === 'error')) continue;
      const again = parseLine(first.normalized, ctx);
      expect(again.normalized, `normalized(normalized) for \`${testCase.input}\``).toBe(first.normalized);
      for (const k of ['schedule', 'unit', 'daily_total', 'duration', 'timing', 'timing_code', 'route_code', 'quantity', 'instruction'] as const) {
        expect(again[k], `${k} for \`${testCase.input}\``).toEqual(first[k]);
      }
    }
  });

  it('treats Bangla digits as defining `raw` and ignores case', () => {
    const line = parseLine('১+০+১ ১০D AF', context('tab'));
    expect(line.raw).toBe('1+0+1 10D AF');
    expect(line.normalized).toBe('1+0+1 10d af');
    expect(line.timing).toBe('after');
  });

  it('splits the instruction keeping case and script', () => {
    const n = normalize('1+0+1 5d //After BREAKFAST with পানি');
    expect(n.body).toBe('1+0+1 5d');
    expect(n.instruction).toBe('After BREAKFAST with পানি');
  });

  it('carries span and suggestion on an unknown token', () => {
    const issues = parseLine('1+0+1 10d aff', context('tab')).issues;
    expect(issues).toHaveLength(1);
    expect(issues[0]?.code).toBe('unknown_token');
    expect(issues[0]?.token).toBe('aff');
    expect(issues[0]?.span).toEqual([10, 13]);
    expect(issues[0]?.suggestion).toBe('af');
    expect(issues[0]?.message).toContain('"af"');
    expect(issues[0]?.message_bn).not.toBe('');
  });
});
