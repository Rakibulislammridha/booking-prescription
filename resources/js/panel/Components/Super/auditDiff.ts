// The audit drawer's diff: `before`/`after` are flat `{column: value}` documents of the attributes that changed
// (SCHEMA §2.13), but a row may carry only one side (a create has no `before`, a delete no `after`) and the two
// sides need not share keys. This folds them into one ordered table — every key once, with what it was and what
// it became — so the drawer can highlight the rows that actually moved instead of printing two JSON blobs.

export interface DiffRow {
  key: string;
  before: unknown;
  after: unknown;
  /** True when the value differs between the two sides (or exists on one side only). */
  changed: boolean;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** A stable, order-insensitive serialisation so `{a:1,b:2}` and `{b:2,a:1}` compare equal. */
export function stable(value: unknown): string {
  if (value === undefined) return 'undefined';
  if (!isRecord(value)) return JSON.stringify(value) ?? 'undefined';
  const keys = Object.keys(value).sort();
  return `{${keys.map((k) => `${JSON.stringify(k)}:${stable(value[k])}`).join(',')}}`;
}

export function diffRows(before: unknown, after: unknown): DiffRow[] {
  const left = isRecord(before) ? before : null;
  const right = isRecord(after) ? after : null;

  // A side that is not a document (a bare string, an array) is shown as one row under `value`.
  if (left === null && right === null) {
    if (before === null && after === null) return [];
    if (before === undefined && after === undefined) return [];
    return [{ key: 'value', before, after, changed: stable(before) !== stable(after) }];
  }

  const keys: string[] = [];
  const seen = new Set<string>();
  for (const key of [...Object.keys(left ?? {}), ...Object.keys(right ?? {})]) {
    if (seen.has(key)) continue;
    seen.add(key);
    keys.push(key);
  }

  return keys.map((key) => {
    const has = { before: left !== null && key in left, after: right !== null && key in right };
    const b = has.before ? left?.[key] : undefined;
    const a = has.after ? right?.[key] : undefined;
    return { key, before: b, after: a, changed: !has.before || !has.after || stable(b) !== stable(a) };
  });
}

/** How a single value is printed in a cell: scalars as-is, documents as compact JSON, absence as a dash. */
export function printValue(value: unknown): string {
  if (value === undefined) return '—';
  if (value === null) return 'null';
  if (typeof value === 'string') return value;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  return JSON.stringify(value);
}
