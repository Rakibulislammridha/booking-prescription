// The audit drawer's before/after table. What matters: a key on one side only counts as a change, a document
// that merely re-ordered its keys does not, and a row that carries no documents renders nothing at all.
import { describe, expect, it } from 'vitest';
import { diffRows, printValue, stable } from '../auditDiff';

describe('diffRows', () => {
  it('folds both sides into one ordered table and marks what moved', () => {
    const rows = diffRows({ name: 'Ops', is_active: true }, { name: 'Ops', is_active: false, sessions_revoked: 2 });

    expect(rows.map((r) => r.key)).toEqual(['name', 'is_active', 'sessions_revoked']);
    expect(rows[0]).toMatchObject({ changed: false });
    expect(rows[1]).toMatchObject({ before: true, after: false, changed: true });
    expect(rows[2]).toMatchObject({ before: undefined, after: 2, changed: true });
  });

  it('treats a re-ordered nested document as unchanged', () => {
    const rows = diffRows({ overrides: { a: 1, b: { c: 2 } } }, { overrides: { b: { c: 2 }, a: 1 } });
    expect(rows).toHaveLength(1);
    expect(rows[0]?.changed).toBe(false);
    expect(stable({ z: 1, a: [1, 2] })).toBe('{"a":[1,2],"z":1}');
  });

  it('shows a create (no before) and a delete (no after) as one-sided changes', () => {
    expect(diffRows(null, { email: 'x@y.test' })).toEqual([{ key: 'email', before: undefined, after: 'x@y.test', changed: true }]);
    expect(diffRows({ email: 'x@y.test' }, null)).toEqual([{ key: 'email', before: 'x@y.test', after: undefined, changed: true }]);
    expect(diffRows(null, null)).toEqual([]);
  });

  it('puts a non-document side under a single value row', () => {
    expect(diffRows('a', 'b')).toEqual([{ key: 'value', before: 'a', after: 'b', changed: true }]);
  });

  it('prints cells the way an operator reads them', () => {
    expect(printValue(undefined)).toBe('—');
    expect(printValue(null)).toBe('null');
    expect(printValue('pro')).toBe('pro');
    expect(printValue(12)).toBe('12');
    expect(printValue({ a: 1 })).toBe('{"a":1}');
  });
});
